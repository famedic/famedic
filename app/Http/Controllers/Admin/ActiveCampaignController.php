<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActiveCampaign\ActiveCampaignAlertsCenterService;
use App\Services\ActiveCampaign\ActiveCampaignAnalyticsService;
use App\Services\ActiveCampaign\ActiveCampaignAutomationCenterService;
use App\Services\ActiveCampaign\ActiveCampaignContactDrawerSectionsService;
use App\Services\ActiveCampaign\ActiveCampaignContactsService;
use App\Services\ActiveCampaign\ActiveCampaignContactTimelineService;
use App\Services\ActiveCampaign\ActiveCampaignConfigurationCenterService;
use App\Services\ActiveCampaign\ActiveCampaignCustomFieldsManagerService;
use App\Services\ActiveCampaign\ActiveCampaignCustomerJourneyService;
use App\Services\ActiveCampaign\ActiveCampaignDashboardService;
use App\Services\ActiveCampaign\ActiveCampaignEcommerceIntelligenceService;
use App\Services\ActiveCampaign\ActiveCampaignEventCenterService;
use App\Services\ActiveCampaign\ActiveCampaignFunnelsIntelligenceService;
use App\Services\ActiveCampaign\ActiveCampaignHealthCenterService;
use App\Services\ActiveCampaign\ActiveCampaignIntegrationsHubService;
use App\Services\ActiveCampaign\ActiveCampaignLaboratoryIntelligenceService;
use App\Services\ActiveCampaign\ActiveCampaignLogsCenterService;
use App\Services\ActiveCampaign\ActiveCampaignMembershipIntelligenceService;
use App\Services\ActiveCampaign\ActiveCampaignMirrorService;
use App\Services\ActiveCampaign\ActiveCampaignNotificationCenterService;
use App\Services\ActiveCampaign\ActiveCampaignQaCompareService;
use App\Services\ActiveCampaign\ActiveCampaignTagsManagerService;
use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use App\Support\ActiveCampaign\MarketingIntelligenceCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActiveCampaignController extends Controller
{
    public function dashboard(Request $request, ActiveCampaignDashboardService $dashboard): Response
    {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $overview = $dashboard->buildOverview($filter);

        return Inertia::render('Admin/ActiveCampaign/Dashboard', [
            'filters' => $filter->toArray(),
            'health' => $overview['health'],
            'business' => $overview['business'],
            'tables' => $overview['tables'],
            'meta' => $overview['meta'],
            'charts' => Inertia::defer(fn () => $dashboard->buildCharts($filter)),
            'alertsUrl' => route('admin.activecampaign.alerts'),
            'logsUrl' => route('admin.activecampaign.logs'),
            'healthUrl' => route('admin.activecampaign.health'),
            'settingsUrl' => route('admin.activecampaign.settings'),
            'eventsUrl' => route('admin.activecampaign.events'),
        ]);
    }

    public function analytics(Request $request, ActiveCampaignAnalyticsService $analytics): Response
    {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $payload = $analytics->build($filter);

        return Inertia::render('Admin/ActiveCampaign/Analytics', [
            'filters' => $payload['filters'],
            'meta' => $payload['meta'],
            'domains' => $payload['domains'],
            'charts' => Inertia::defer(fn () => $analytics->buildCharts($filter)),
            'dashboardUrl' => route('admin.activecampaign.dashboard'),
            'contactsUrl' => route('admin.activecampaign.contacts'),
            'journeyUrl' => route('admin.activecampaign.customer-journey'),
            'logsUrl' => route('admin.activecampaign.logs'),
            'healthUrl' => route('admin.activecampaign.health'),
        ]);
    }

    public function contacts(
        Request $request,
        ActiveCampaignContactsService $contacts,
        ActiveCampaignContactDrawerSectionsService $drawerSections,
        ActiveCampaignContactTimelineService $timeline,
        ActiveCampaignMirrorService $mirror,
    ): Response {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $drawerContactId = $request->integer('drawer_contact_id') ?: null;
        $drawerSectionKey = (string) $request->input('drawer_section', '');
        $partialOnly = array_values(array_filter(explode(',', (string) $request->header('X-Inertia-Partial-Data', ''))));

        if ($partialOnly === ['drawerExtras'] && $drawerContactId) {
            return Inertia::render('Admin/ActiveCampaign/Contacts', [
                'drawerExtras' => $contacts->buildDrawerExtras(
                    $drawerContactId,
                    $timeline,
                    $drawerSections,
                    $mirror,
                ),
            ]);
        }

        $drawerSection = null;
        if (
            $drawerContactId
            && $drawerSectionKey !== ''
            && in_array($drawerSectionKey, ActiveCampaignContactDrawerSectionsService::SECTIONS, true)
        ) {
            $drawerSection = $drawerSections->build($drawerContactId, $drawerSectionKey);
        }

        if ($partialOnly === ['drawerSection']) {
            return Inertia::render('Admin/ActiveCampaign/Contacts', [
                'drawerSection' => $drawerSection,
            ]);
        }

        $drawer = null;
        if ($drawerContactId && $drawerSectionKey === '') {
            $drawer = $contacts->buildDrawerSummary($drawerContactId);
        }

        if ($partialOnly === ['drawer']) {
            return Inertia::render('Admin/ActiveCampaign/Contacts', [
                'drawer' => $drawer,
            ]);
        }

        $payload = $contacts->list($request);

        return Inertia::render('Admin/ActiveCampaign/Contacts', [
            'contacts' => $payload['contacts'],
            'filters' => $payload['filters'],
            'drawer' => $drawer,
            'drawerSection' => null,
            'drawerExtras' => null,
            'canExport' => false,
        ]);
    }

    public function patient360(Request $request): Response
    {
        return $this->shell($request, 'patient-360');
    }

    public function customerJourney(
        Request $request,
        ActiveCampaignCustomerJourneyService $journey,
    ): Response {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $partialOnly = array_values(array_filter(explode(',', (string) $request->header('X-Inertia-Partial-Data', ''))));
        $contactId = $request->integer('contact_id') ?: null;
        $nodeId = (string) $request->input('node_id', '');

        if ($partialOnly === ['journeyDetail'] && $contactId && $nodeId !== '') {
            return Inertia::render('Admin/ActiveCampaign/CustomerJourney', [
                'journeyDetail' => $journey->buildNodeDetail($contactId, $nodeId, [
                    'start_date' => (string) $request->input('start_date', ''),
                    'end_date' => (string) $request->input('end_date', ''),
                    'type' => (string) $request->input('type', ''),
                ]),
            ]);
        }

        $page = $journey->buildPage($request);

        return Inertia::render('Admin/ActiveCampaign/CustomerJourney', [
            'filters' => $page['filters'],
            'contactOptions' => $page['contactOptions'],
            'journey' => $page['journey'],
            'journeyDetail' => null,
            'contactsUrl' => route('admin.activecampaign.contacts'),
        ]);
    }

    public function automations(
        Request $request,
        ActiveCampaignAutomationCenterService $automations,
    ): Response {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $page = $automations->buildDashboard($filter);

        return Inertia::render('Admin/ActiveCampaign/Automations/Dashboard', [
            'metrics' => $page['metrics'],
            'recentRunsPreview' => $page['recent_runs_preview'],
            'catalogPreview' => $page['catalog_preview'],
            'meta' => $page['meta'],
            'links' => $page['links'],
        ]);
    }

    public function automationsList(
        Request $request,
        ActiveCampaignAutomationCenterService $automations,
    ): Response {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $page = $automations->buildList();

        return Inertia::render('Admin/ActiveCampaign/Automations/List', [
            'items' => $page['items'],
            'meta' => $page['meta'],
            'links' => $page['links'],
        ]);
    }

    public function automationsBuilder(
        Request $request,
        ActiveCampaignAutomationCenterService $automations,
    ): Response {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $page = $automations->buildBuilder($request->string('preset')->toString() ?: null);

        return Inertia::render('Admin/ActiveCampaign/Automations/Builder', [
            'events' => $page['events'],
            'conditionTemplates' => $page['condition_templates'],
            'actionTemplates' => $page['action_templates'],
            'preset' => $page['preset'],
            'save' => $page['save'],
            'links' => $page['links'],
            'meta' => $page['meta'],
        ]);
    }

    public function automationsShow(
        Request $request,
        string $automation,
        ActiveCampaignAutomationCenterService $automations,
    ): Response {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $page = $automations->buildDetail($automation);
        abort_if($page === null, 404);

        return Inertia::render('Admin/ActiveCampaign/Automations/Detail', [
            'automation' => $page['automation'],
            'info' => $page['info'],
            'conditions' => $page['conditions'],
            'actions' => $page['actions'],
            'links' => $page['links'],
            'deferred' => Inertia::defer(
                fn () => $automations->buildDetailDeferred($automation)
            ),
        ]);
    }

    public function funnels(
        Request $request,
        ActiveCampaignFunnelsIntelligenceService $funnels,
    ): Response {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $core = $funnels->build($request);

        return Inertia::render('Admin/ActiveCampaign/FunnelsIntelligence', [
            'filters' => $core['filters'],
            'funnelOptions' => $core['funnelOptions'],
            'summary' => $core['summary'],
            'funnel' => $core['funnel'],
            'metrics' => $core['metrics'],
            'insights' => $core['insights'],
            'recommendations' => $core['recommendations'],
            'risks' => $core['risks'],
            'suggested_actions' => $core['suggested_actions'],
            'gaps' => $core['gaps'],
            'meta' => $core['meta'],
            'charts' => Inertia::defer(fn () => $funnels->buildCharts($request)),
        ]);
    }

    public function events(Request $request, ActiveCampaignEventCenterService $eventCenter): Response
    {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $partialOnly = array_values(array_filter(explode(',', (string) $request->header('X-Inertia-Partial-Data', ''))));
        $eventId = trim((string) $request->input('event_id', ''));
        $detailContactId = $request->integer('event_contact_id') ?: null;

        if ($partialOnly === ['eventDetail'] && $eventId !== '') {
            return Inertia::render('Admin/ActiveCampaign/EventCenter', [
                'eventDetail' => $eventCenter->buildEventDetail($eventId, $detailContactId),
            ]);
        }

        $core = $eventCenter->buildCore($request);

        return Inertia::render('Admin/ActiveCampaign/EventCenter', [
            'filters' => $core['filters'],
            'filterOptions' => $core['filterOptions'],
            'summary' => $core['summary'],
            'actions' => $core['actions'],
            'contactOptions' => $core['contactOptions'],
            'meta' => $core['meta'],
            'events' => Inertia::defer(fn () => $eventCenter->buildEvents($request)),
            'eventDetail' => null,
        ]);
    }

    public function tags(
        Request $request,
        ActiveCampaignTagsManagerService $tags,
    ): Response {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $partialOnly = array_values(array_filter(explode(',', (string) $request->header('X-Inertia-Partial-Data', ''))));
        $tagId = trim((string) $request->input('tag_id', ''));

        if ($partialOnly === ['tagDetail'] && $tagId !== '') {
            return Inertia::render('Admin/ActiveCampaign/TagsManager', [
                'tagDetail' => $tags->buildDetail($tagId, $request),
            ]);
        }

        $inbox = $tags->buildInbox($request);

        return Inertia::render('Admin/ActiveCampaign/TagsManager', [
            'filters' => $inbox['filters'],
            'filterOptions' => $inbox['filterOptions'],
            'summary' => $inbox['summary'],
            'tags' => $inbox['tags'],
            'actions' => $inbox['actions'],
            'meta' => $inbox['meta'],
            'tagDetail' => null,
            'executive' => Inertia::defer(fn () => $tags->buildExecutive($request)),
        ]);
    }

    public function fields(
        Request $request,
        ActiveCampaignCustomFieldsManagerService $fields,
    ): Response {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $partialOnly = array_values(array_filter(explode(',', (string) $request->header('X-Inertia-Partial-Data', ''))));
        $fieldId = trim((string) $request->input('field_id', ''));

        if ($partialOnly === ['fieldDetail'] && $fieldId !== '') {
            return Inertia::render('Admin/ActiveCampaign/CustomFieldsManager', [
                'fieldDetail' => $fields->buildDetail($fieldId, $request),
            ]);
        }

        $inbox = $fields->buildInbox($request);

        return Inertia::render('Admin/ActiveCampaign/CustomFieldsManager', [
            'filters' => $inbox['filters'],
            'filterOptions' => $inbox['filterOptions'],
            'summary' => $inbox['summary'],
            'fields' => $inbox['fields'],
            'actions' => $inbox['actions'],
            'meta' => $inbox['meta'],
            'fieldDetail' => null,
            'executive' => Inertia::defer(fn () => $fields->buildExecutive($request)),
        ]);
    }

    public function ecommerce(
        Request $request,
        ActiveCampaignEcommerceIntelligenceService $ecommerce,
    ): Response {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $core = $ecommerce->build($request);

        return Inertia::render('Admin/ActiveCampaign/EcommerceIntelligence', [
            'filters' => $core['filters'],
            'summary' => $core['summary'],
            'kpis' => $core['kpis'],
            'distribution' => $core['distribution'],
            'payment_methods' => $core['payment_methods'],
            'top_products' => $core['top_products'],
            'coupons' => $core['coupons'],
            'insights' => $core['insights'],
            'recommendations' => $core['recommendations'],
            'risks' => $core['risks'],
            'suggested_actions' => $core['suggested_actions'],
            'gaps' => $core['gaps'],
            'meta' => $core['meta'],
            'charts' => Inertia::defer(fn () => $ecommerce->buildCharts($request)),
        ]);
    }

    public function laboratories(
        Request $request,
        ActiveCampaignLaboratoryIntelligenceService $laboratories,
    ): Response {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $core = $laboratories->build($request);

        return Inertia::render('Admin/ActiveCampaign/LaboratoryIntelligence', [
            'filters' => $core['filters'],
            'summary' => $core['summary'],
            'kpis' => $core['kpis'],
            'top_laboratories' => $core['top_laboratories'],
            'top_studies' => $core['top_studies'],
            'insights' => $core['insights'],
            'recommendations' => $core['recommendations'],
            'risks' => $core['risks'],
            'suggested_actions' => $core['suggested_actions'],
            'gaps' => $core['gaps'],
            'meta' => $core['meta'],
            'charts' => Inertia::defer(fn () => $laboratories->buildCharts($request)),
        ]);
    }

    public function memberships(
        Request $request,
        ActiveCampaignMembershipIntelligenceService $memberships,
    ): Response {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $core = $memberships->build($request);

        return Inertia::render('Admin/ActiveCampaign/MembershipIntelligence', [
            'filters' => $core['filters'],
            'summary' => $core['summary'],
            'kpis' => $core['kpis'],
            'distribution' => $core['distribution'],
            'insights' => $core['insights'],
            'recommendations' => $core['recommendations'],
            'risks' => $core['risks'],
            'suggested_actions' => $core['suggested_actions'],
            'gaps' => $core['gaps'],
            'meta' => $core['meta'],
            'charts' => Inertia::defer(fn () => $memberships->buildCharts($request)),
        ]);
    }

    public function alerts(
        Request $request,
        ActiveCampaignAlertsCenterService $alerts,
    ): Response {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $partialOnly = array_values(array_filter(explode(',', (string) $request->header('X-Inertia-Partial-Data', ''))));
        $alertId = trim((string) $request->input('alert_id', ''));

        if ($partialOnly === ['alertDetail'] && $alertId !== '') {
            return Inertia::render('Admin/ActiveCampaign/AlertsCenter', [
                'alertDetail' => $alerts->buildDetail($alertId, $request),
            ]);
        }

        $inbox = $alerts->buildInbox($request);

        return Inertia::render('Admin/ActiveCampaign/AlertsCenter', [
            'filters' => $inbox['filters'],
            'filterOptions' => $inbox['filterOptions'],
            'summary' => $inbox['summary'],
            'alerts' => $inbox['alerts'],
            'actions' => $inbox['actions'],
            'meta' => $inbox['meta'],
            'alertDetail' => null,
            'executive' => Inertia::defer(fn () => $alerts->buildExecutive($request)),
        ]);
    }

    public function notifications(
        Request $request,
        ActiveCampaignNotificationCenterService $notifications,
    ): Response {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $partialOnly = array_values(array_filter(explode(',', (string) $request->header('X-Inertia-Partial-Data', ''))));
        $notificationId = trim((string) $request->input('notification_id', ''));

        if ($partialOnly === ['notificationDetail'] && $notificationId !== '') {
            return Inertia::render('Admin/ActiveCampaign/NotificationCenter', [
                'notificationDetail' => $notifications->buildDetail($notificationId),
            ]);
        }

        $inbox = $notifications->buildInbox($request);

        return Inertia::render('Admin/ActiveCampaign/NotificationCenter', [
            'filters' => $inbox['filters'],
            'filterOptions' => $inbox['filterOptions'],
            'summary' => $inbox['summary'],
            'notifications' => $inbox['notifications'],
            'actions' => $inbox['actions'],
            'meta' => $inbox['meta'],
            'notificationDetail' => null,
        ]);
    }

    public function logs(
        Request $request,
        ActiveCampaignLogsCenterService $logs,
    ): Response {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $partialOnly = array_values(array_filter(explode(',', (string) $request->header('X-Inertia-Partial-Data', ''))));
        $logId = trim((string) $request->input('log_id', ''));

        if ($partialOnly === ['logDetail'] && $logId !== '') {
            return Inertia::render('Admin/ActiveCampaign/LogsCenter', [
                'logDetail' => $logs->buildDetail($logId, $request),
            ]);
        }

        $inbox = $logs->buildInbox($request);

        return Inertia::render('Admin/ActiveCampaign/LogsCenter', [
            'filters' => $inbox['filters'],
            'filterOptions' => $inbox['filterOptions'],
            'summary' => $inbox['summary'],
            'logs' => $inbox['logs'],
            'actions' => $inbox['actions'],
            'meta' => $inbox['meta'],
            'logDetail' => null,
            'executive' => Inertia::defer(fn () => $logs->buildExecutive($request)),
        ]);
    }

    public function health(Request $request, ActiveCampaignHealthCenterService $healthCenter): Response
    {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $core = $healthCenter->buildCore($filter);

        return Inertia::render('Admin/ActiveCampaign/HealthCenter', [
            'filters' => $filter->toArray(),
            'overview' => $core['overview'],
            'integrations' => $core['integrations'],
            'actions' => $core['actions'],
            'meta' => $core['meta'],
            'deferred' => Inertia::defer(fn () => $healthCenter->buildDeferred($filter)),
        ]);
    }

    public function integrations(Request $request, ActiveCampaignIntegrationsHubService $hub): Response
    {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $core = $hub->buildCore($filter);

        return Inertia::render('Admin/ActiveCampaign/IntegrationsHub', [
            'filters' => $filter->toArray(),
            'summary' => $core['summary'],
            'integrations' => $core['integrations'],
            'meta' => $core['meta'],
            'deferred' => Inertia::defer(fn () => $hub->buildDeferred()),
            'healthUrl' => route('admin.activecampaign.health'),
            'logsUrl' => route('admin.activecampaign.logs'),
            'settingsUrl' => route('admin.activecampaign.settings'),
        ]);
    }

    public function qaCompare(
        Request $request,
        ActiveCampaignQaCompareService $qaCompare,
    ): Response {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $partialOnly = array_values(array_filter(explode(',', (string) $request->header('X-Inertia-Partial-Data', ''))));
        $rowId = trim((string) $request->input('row_id', ''));

        if ($partialOnly === ['compareDetail'] && $rowId !== '') {
            return Inertia::render('Admin/ActiveCampaign/QaCompare', [
                'compareDetail' => $qaCompare->buildDetail($rowId, $request),
            ]);
        }

        $inbox = $qaCompare->buildInbox($request);

        return Inertia::render('Admin/ActiveCampaign/QaCompare', [
            'filters' => $inbox['filters'],
            'filterOptions' => $inbox['filterOptions'],
            'summary' => $inbox['summary'],
            'rows' => $inbox['rows'],
            'actions' => $inbox['actions'],
            'meta' => $inbox['meta'],
            'compareDetail' => null,
            'executive' => Inertia::defer(fn () => $qaCompare->buildExecutive($request)),
        ]);
    }

    public function settings(
        Request $request,
        ActiveCampaignConfigurationCenterService $configuration,
    ): Response {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $partialOnly = array_values(array_filter(explode(',', (string) $request->header('X-Inertia-Partial-Data', ''))));
        $configId = trim((string) $request->input('config_id', ''));

        if ($partialOnly === ['configDetail'] && $configId !== '') {
            return Inertia::render('Admin/ActiveCampaign/ConfigurationCenter', [
                'configDetail' => $configuration->buildDetail($configId, $request),
            ]);
        }

        $inbox = $configuration->buildInbox($request);

        return Inertia::render('Admin/ActiveCampaign/ConfigurationCenter', [
            'filters' => $inbox['filters'],
            'filterOptions' => $inbox['filterOptions'],
            'summary' => $inbox['summary'],
            'configs' => $inbox['configs'],
            'actions' => $inbox['actions'],
            'meta' => $inbox['meta'],
            'configDetail' => null,
            'executive' => Inertia::defer(fn () => $configuration->buildExecutive($request)),
        ]);
    }

    private function shell(Request $request, string $key): Response
    {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        return Inertia::render('Admin/ActiveCampaign/Shell', [
            'page' => MarketingIntelligenceCatalog::get($key),
        ]);
    }
}
