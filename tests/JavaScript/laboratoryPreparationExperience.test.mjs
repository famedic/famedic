import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const source = readFileSync(
	"resources/js/Components/LaboratoryOrderDetail/InstructionsContent.jsx",
	"utf8",
);

test("laboratory preparation experience renders sections as the primary AI_READY content", () => {
	assert.match(source, /function PreparationOverview/);
	assert.match(source, /function PreparationSummary/);
	assert.match(source, /function PreparationSectionBlock/);
	assert.match(source, /summary\.sections/);
	assert.match(source, /sections\.map/);
	assert.match(source, /Preparación para tus estudios/);
	assert.match(
		source,
		/Hemos simplificado las indicaciones de tus estudios para que sea más fácil prepararte/,
	);
	assert.match(source, /SparklesIcon/);
	assert.doesNotMatch(source, /Lo esencial para prepararte/);
	assert.doesNotMatch(source, /summary\.text/);
});

test("laboratory preparation experience shows special instructions only when present", () => {
	assert.match(source, /function SpecialInstructionsBlock/);
	assert.match(source, /summary\.special_instructions/);
	assert.match(source, /Importante/);
	assert.match(source, /if \(!instructions\.length\) return null/);
	assert.match(source, /summary\.special_instructions\.filter/);
	assert.match(source, /instruction\?\.content/);
});

test("laboratory preparation experience handles pending, failed, and fallback states safely", () => {
	assert.match(source, /PREPARATION_STATUS/);
	assert.match(source, /AI_READY/);
	assert.match(source, /AI_PENDING/);
	assert.match(source, /AI_FAILED/);
	assert.match(source, /FALLBACK/);
	assert.match(
		source,
		/Estamos preparando un resumen más sencillo de\s+estas indicaciones/,
	);
	assert.match(
		source,
		/Consulta las indicaciones de cada estudio antes de acudir al laboratorio/,
	);
	assert.match(
		source,
		/No hay estudios con indicaciones de preparación para esta orden/,
	);
	assert.doesNotMatch(source, /Sin indicaciones registradas/);
	assert.doesNotMatch(source, /ClockIcon/);
	assert.doesNotMatch(source, /spinner/i);
});

test("laboratory preparation experience shows original instructions as main content outside AI_READY", () => {
	assert.match(source, /showOriginalInstructionsAsMain/);
	assert.match(source, /hasSummary \?/);
	assert.match(source, /IndividualInstructions studies=\{studies\}/);
	assert.match(source, /studiesWithInstructions/);
	assert.match(source, /studyHasInstructions/);
});

test("laboratory preparation experience keeps original study instructions in an accessible disclosure", () => {
	assert.match(source, /function OriginalInstructionsDisclosure/);
	assert.match(source, /useState\(defaultOpen/);
	assert.match(source, /useId\(\)/);
	assert.match(source, /aria-expanded=\{open\}/);
	assert.match(source, /aria-controls=\{contentId\}/);
	assert.match(source, /type="button"/);
	assert.match(source, /setOpen\(\(current\) => !current\)/);
	assert.match(source, /Ver indicaciones originales/);
	assert.match(
		source,
		/Consulta las instrucciones específicas de cada\s+estudio/,
	);
	assert.match(source, /hidden=\{!open\}/);
	assert.match(source, /role="region"/);
	assert.match(source, /aria-labelledby=\{triggerId\}/);
	assert.match(source, /ChevronDownIcon/);
	assert.match(source, /rotate-180/);
	assert.match(source, /compact/);
	assert.match(source, /feature_list/);
});

test("laboratory preparation experience hides studies without instructions and internal identifiers", () => {
	assert.doesNotMatch(source, /study\.id/);
	assert.doesNotMatch(source, /source_item_ids/);
	assert.match(source, /studyInstructions/);
	assert.match(source, /instructions !== "—"/);
	assert.match(source, /whitespace-pre-wrap/);
});

test("laboratory preparation experience does not expose internal AI implementation details", () => {
	assert.doesNotMatch(source, /OpenAI/i);
	assert.doesNotMatch(source, /GPT/i);
	assert.doesNotMatch(source, /\bprompt\b/i);
	assert.doesNotMatch(source, /\btoken\b/i);
	assert.doesNotMatch(source, /fetch\(/);
	assert.doesNotMatch(source, /axios/);
	assert.doesNotMatch(source, /chatCompletion/);
	assert.doesNotMatch(source, /inteligencia artificial/i);
	assert.doesNotMatch(source, /Preparación simplificada/);
	assert.doesNotMatch(
		source,
		/Resumen generado a partir de las indicaciones/,
	);
	assert.doesNotMatch(source, /\bIA\b/);
});
