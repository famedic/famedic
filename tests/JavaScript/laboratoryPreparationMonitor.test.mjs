import test from "node:test";
import assert from "node:assert/strict";
import { filterValues, filterQuery, advancedFilterKeys, primaryMetricIds, statusLabel, formatDate } from "../../resources/js/Pages/Admin/LaboratoryPreparation/monitorPresentation.js";

test("filter state round trips every supported URL filter and excludes unrelated query parameters", () => {
 const query = {search:"GDA 123",date_from:"2026-10-01",date_to:"2026-10-16",decision_status:"FALLBACK_ORIGINAL",brand:"olab",technical_status:"failed",fallback_category:"source_quality",fallback_reason:"incomplete",openai_status:"failed",needs_provider_review:"false",rule:"R04",mode:"v3"};
 assert.deepEqual(filterQuery(filterValues({...query,redirect:"https://example.test",page:2})),query);
 assert.equal(advancedFilterKeys.length,7);
});
test("clearing filters removes advanced values as well as basic values", () => {
 assert.deepEqual(filterQuery(filterValues()),{});
 assert.ok(Object.values(filterValues()).every(value => value === ""));
});
test("primary indicators retain explicit priority independent of backend ordering", () => {
 assert.deepEqual(primaryMetricIds,["total","auto","fallback","review","technical"]);
});
test("patient and technical states have readable labels without relying on color", () => {
 assert.equal(statusLabel("failed"),"Fallo técnico");
 assert.equal(statusLabel("queued"),"En cola");
 assert.equal(statusLabel("AUTO_CONSOLIDATED"),"AUTO · Consolidado");
 assert.equal(statusLabel(null),"Sin decisión");
});
test("dates are shown in Mexico City even when the browser uses another timezone", () => {
 const formatted = formatDate("2026-10-17T01:30:00Z");
 assert.match(formatted,/16/);
 assert.match(formatted,/7:30|19:30/);
 assert.equal(formatDate(null),"—");
});
