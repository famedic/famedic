<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActiveCampaign\ActiveCampaignAnalyticsService;
use App\Services\ActiveCampaign\ActiveCampaignContactDrawerSectionsService;
use App\Services\ActiveCampaign\ActiveCampaignContactsService;
use App\Services\ActiveCampaign\ActiveCampaignContactTimelineService;
use App\Services\ActiveCampaign\ActiveCampaignCustomerJourneyService;
use App\Services\ActiveCampaign\ActiveCampaignDashboardService;
use App\Services\ActiveCampaign\ActiveCampaignEventCenterService;
use App\Services\ActiveCampaign\ActiveCampaignHealthCenterService;
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

    public function automations(Request $request): Response
    {
        return $this->shell($request, 'automations');
    }

    public function funnels(Request $request): Response
    {
        return $this->shell($request, 'funnels');
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

    public function tags(Request $request): Response
    {
        return $this->shell($request, 'tags');
    }

    public function fields(Request $request): Response
    {
        return $this->shell($request, 'fields');
    }

    public function ecommerce(Request $request): Response
    {
        return $this->shell($request, 'ecommerce');
    }

    public function laboratories(Request $request): Response
    {
        return $this->shell($request, 'laboratories');
    }

    public function memberships(Request $request): Response
    {
        return $this->shell($request, 'memberships');
    }

    public function alerts(Request $request): Response
    {
        return $this->shell($request, 'alerts');
    }

    public function logs(Request $request): Response
    {
        return $this->shell($request, 'logs');
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

    public function qaCompare(Request $request): Response
    {
        return $this->shell($request, 'qa-compare');
    }

    public function settings(Request $request): Response
    {
        return $this->shell($request, 'settings');
    }

    private function shell(Request $request, string $key): Response
    {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        return Inertia::render('Admin/ActiveCampaign/Shell', [
            'page' => MarketingIntelligenceCatalog::get($key),
        ]);
    }
}
