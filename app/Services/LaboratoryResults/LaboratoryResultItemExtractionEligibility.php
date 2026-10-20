<?php

namespace App\Services\LaboratoryResults;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryTest;
use Illuminate\Support\Facades\Schema;

class LaboratoryResultItemExtractionEligibility
{
    public const REASON_IMAGING_CATEGORY = 'imaging_category';

    public const REASON_IMAGING_STUDY_NAME = 'imaging_study_name';

    public const REASON_CLINICAL_CATEGORY = 'clinical_laboratory_category';

    public const REASON_CLINICAL_STUDY_NAME = 'clinical_laboratory_study_name';

    public const REASON_UNKNOWN_STUDY_TYPE = 'unknown_study_type';

    public const SOURCE_CATALOG_CATEGORY = 'catalog_category';

    public const SOURCE_CATALOG_TEST_NAME = 'catalog_test_name';

    public const SOURCE_PURCHASE_ITEM_NAME = 'purchase_item_name';

    /**
     * Categorías GDA importadas como paquetes/checkups: no son modalidad confiable por sí solas.
     */
    private const PACKAGE_CATEGORY_PATTERNS = [
        '/paquete/u',
        '/chequeo/u',
        '/checkup/u',
    ];

    /**
     * Modalidades de imagen/gabinete en laboratory_test_categories.name (texto GDA).
     */
    private const IMAGING_CATEGORY_PATTERNS = [
        '/ultrasonid/u',
        '/ecograf/u',
        '/resonancia/u',
        '/tomograf/u',
        '/radiograf/u',
        '/rayos\s*x/u',
        '/mastograf/u',
        '/densitomet/u',
        '/gammagram/u',
        '/medicina\s*nuclear/u',
        '/imagenolog/u',
        '/angioresonancia/u',
        '/angiotac/u',
        '/electrocardi/u',
        '/ecocardi/u',
        '/espiromet/u',
        '/holter/u',
        '/polisomnograf/u',
        '/electroencefal/u',
        '/ortopantomograf/u',
    ];

    /**
     * Categorías de laboratorio clínico analítico en laboratory_test_categories.name.
     */
    private const CLINICAL_CATEGORY_PATTERNS = [
        '/quimica/u',
        '/bioquim/u',
        '/hematolog/u',
        '/coagulacion/u',
        '/hormon/u',
        '/microbiolog/u',
        '/inmunolog/u',
        '/serolog/u',
        '/uroanalisis/u',
        '/orina/u',
        '/parasitolog/u',
        '/toxicolog/u',
        '/hemogram/u',
    ];

    /**
     * Prefijos/nombres de estudios de imagen cuando no hay categoría confiable.
     * Conservador: solo patrones de modalidad explícita (no "muchas cifras => lab").
     */
    private const IMAGING_NAME_PATTERNS = [
        '/\beco\b/u',
        '/\bus\b/u',
        '/\brmn\b/u',
        '/\btac\b/u',
        '/\brx\b/u',
        '/radiograf/u',
        '/angioresonancia/u',
        '/angiotac/u',
        '/resonancia/u',
        '/tomograf/u',
        '/ultrasonid/u',
        '/ecograf/u',
        '/mastograf/u',
        '/densitomet/u',
        '/cefalogram/u',
        '/reporte\s+radiolog/u',
    ];

    /**
     * Nombres de estudios de laboratorio clínico estructurable (analitos tabulares).
     */
    private const CLINICAL_NAME_PATTERNS = [
        '/\bbioquim/u',
        '/quimica\s*sangu/u',
        '/\bbh\b/u',
        '/biometria\s*hemat/u',
        '/hemogram/u',
        '/perfil\s+(bioquim|tiroideo|hepatic|lipid|hormon)/u',
        '/examen\s+general\s+de\s+orina/u',
        '/urocultivo/u',
        '/hemoglobina\s+glicosilada/u',
        '/glicohemoglobina/u',
    ];

    public function isEligible(LaboratoryPurchaseItem $item, LaboratoryBrand $brand): bool
    {
        return $this->evaluate($item, $brand)->eligible;
    }

    public function evaluate(LaboratoryPurchaseItem $item, LaboratoryBrand $brand): LaboratoryResultItemExtractionEligibilityResult
    {
        $catalogTest = $this->resolveCatalogTest($item, $brand);

        if ($catalogTest?->laboratoryTestCategory !== null) {
            $categoryName = $this->normalizeText($catalogTest->laboratoryTestCategory->name);

            if (! $this->isPackageCategory($categoryName)) {
                if ($this->matchesAny($categoryName, self::IMAGING_CATEGORY_PATTERNS)) {
                    return $this->ineligible(self::REASON_IMAGING_CATEGORY, self::SOURCE_CATALOG_CATEGORY);
                }

                if ($this->matchesAny($categoryName, self::CLINICAL_CATEGORY_PATTERNS)) {
                    return $this->eligible(self::REASON_CLINICAL_CATEGORY, self::SOURCE_CATALOG_CATEGORY);
                }
            }
        }

        if ($catalogTest !== null) {
            $catalogName = $this->normalizeText(trim($catalogTest->name.' '.$catalogTest->other_name));

            if ($this->matchesAny($catalogName, self::IMAGING_NAME_PATTERNS)) {
                return $this->ineligible(self::REASON_IMAGING_STUDY_NAME, self::SOURCE_CATALOG_TEST_NAME);
            }

            if ($this->matchesAny($catalogName, self::CLINICAL_NAME_PATTERNS)) {
                return $this->eligible(self::REASON_CLINICAL_STUDY_NAME, self::SOURCE_CATALOG_TEST_NAME);
            }
        }

        $itemName = $this->normalizeText((string) $item->name);

        if ($itemName !== '') {
            if ($this->matchesAny($itemName, self::IMAGING_NAME_PATTERNS)) {
                return $this->ineligible(self::REASON_IMAGING_STUDY_NAME, self::SOURCE_PURCHASE_ITEM_NAME);
            }

            if ($this->matchesAny($itemName, self::CLINICAL_NAME_PATTERNS)) {
                return $this->eligible(self::REASON_CLINICAL_STUDY_NAME, self::SOURCE_PURCHASE_ITEM_NAME);
            }
        }

        // Sin señal confiable: no extraer (evita asociar analitos a estudios desconocidos).
        return $this->ineligible(self::REASON_UNKNOWN_STUDY_TYPE, self::SOURCE_PURCHASE_ITEM_NAME);
    }

    private function resolveCatalogTest(LaboratoryPurchaseItem $item, LaboratoryBrand $brand): ?LaboratoryTest
    {
        if ($item->gda_id === null || $item->gda_id === '') {
            return null;
        }

        if (! Schema::hasTable('laboratory_tests')) {
            return null;
        }

        return LaboratoryTest::query()
            ->with('laboratoryTestCategory')
            ->where('brand', $brand)
            ->where('gda_id', $item->gda_id)
            ->first();
    }

    private function isPackageCategory(string $normalizedCategoryName): bool
    {
        return $this->matchesAny($normalizedCategoryName, self::PACKAGE_CATEGORY_PATTERNS);
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAny(string $normalizedText, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalizedText)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, [
            'á' => 'a',
            'à' => 'a',
            'ä' => 'a',
            'â' => 'a',
            'é' => 'e',
            'è' => 'e',
            'ë' => 'e',
            'ê' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'ï' => 'i',
            'î' => 'i',
            'ó' => 'o',
            'ò' => 'o',
            'ö' => 'o',
            'ô' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'ü' => 'u',
            'û' => 'u',
            'ñ' => 'n',
        ]);
        $text = preg_replace('/[^\p{L}\p{N}\s.]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function eligible(string $reason, string $source): LaboratoryResultItemExtractionEligibilityResult
    {
        return new LaboratoryResultItemExtractionEligibilityResult(
            eligible: true,
            reason: $reason,
            source: $source,
        );
    }

    private function ineligible(string $reason, string $source): LaboratoryResultItemExtractionEligibilityResult
    {
        return new LaboratoryResultItemExtractionEligibilityResult(
            eligible: false,
            reason: $reason,
            source: $source,
        );
    }
}
