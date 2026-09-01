#!/usr/bin/env bash
set -uo pipefail
RESULT="${1:-/tmp/regression_full.txt}"
: > "${RESULT}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

run() {
  "${SCRIPT_DIR}/run-regression-batch.sh" "${RESULT}" "$@"
}

# Appointment-first
run tests/Feature/Laboratory/LaboratoryCheckoutFlowEligibilityTest.php
run tests/Feature/Laboratory/LaboratoryCheckoutAppointmentFirstFlowTest.php
run tests/Feature/Laboratory/LaboratoryCheckoutAppointmentFirstPhase4Test.php
run tests/Feature/Laboratory/LaboratoryCheckoutAppointmentFirstPhase5Test.php
run tests/Feature/Laboratory/PromoCodeCheckoutTest.php

# Carritos y abandono
run tests/Feature/Carts/CartAbandonmentEpisodeTest.php
run tests/Feature/Carts/CartPaymentMethodSelectedTest.php
run tests/Feature/Carts/CartResumeRecoveredTest.php
run tests/Feature/Carts/CartAbandonmentRegressionTest.php
run tests/Feature/CartJourneyCheckoutFlowTest.php
run tests/Feature/CartAbandonmentEpisodeTest.php

# ActiveCampaign Feature
run tests/Feature/CartActiveCampaignOutboxTest.php
run tests/Feature/CartActiveCampaignSiteEventsTest.php
run tests/Feature/CartActiveCampaignAppointmentCallSignalsTest.php
run tests/Feature/ActiveCampaignWebActivitySyncTest.php
run tests/Feature/ActiveCampaignDashboardRegressionTest.php

# ActiveCampaign Unit
for f in tests/Unit/ActiveCampaign/*.php; do
  run "${f}"
done
run tests/Unit/Orders/ActiveCampaignOrderDriverTest.php

# Administración
run tests/Feature/Admin/Carts/CartAdminPhase6Test.php
run tests/Feature/AdminCartsExportReportTest.php
run tests/Feature/AdminCart360DetailTest.php
run tests/Feature/AdminCartOperationalBucketsTest.php
run tests/Feature/AdminCartPaymentAttemptCorrelatorTest.php
run tests/Feature/AdminCartTraceabilityTest.php
run tests/Feature/AdminCartsDashboardAnalyticsTest.php
run tests/Feature/AdminCartsUxFiltersTest.php
run tests/Unit/Exports/MonterreyExcelSerialTest.php
run tests/Feature/CartTraceabilityAuditCommandTest.php
run tests/Feature/BackfillAppointmentCartLinksCommandTest.php

# Pagos y correo
run tests/Feature/PayPal/PayPalCreateOrderRouteTest.php
run tests/Feature/Coupons/LaboratoryPurchaseCouponReversalTest.php
run tests/Feature/Coupons/LaboratoryPurchaseCouponReversalPhase3Test.php

echo "===== REGRESSION SUMMARY =====" | tee -a "${RESULT}"
grep -E '^(=====|Tests:|EXIT:)' "${RESULT}" | tee -a "${RESULT}"
