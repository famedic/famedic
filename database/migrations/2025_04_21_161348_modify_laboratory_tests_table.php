<?php

use App\Models\LaboratoryTest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public $tests = [
        "BIOMETRIA HEMATICA COMPLETA" => "Diagnóstico de anemia, infecciones, leucemias, trombocitopenia",
        "PERFIL TIROIDEO (T-UPTAKE,FT7,T3,T3RU,T4,FT4,TSH,IPROT)" => "Evaluación de función tiroidea; detección de hipotiroidismo e hipertiroidismo",
        "EXAMEN GENERAL DE ORINA" => "Diagnóstico de infecciones urinarias, enfermedades renales, control metabólico",
        "PERFIL BIOQUIMICO 24 (ELEC,CALCIO,FOS,TGP)" => "Evaluación metabólica completa, función renal y hepática",
        "VITAMINA D (25 HIDROXI)" => "Evaluación de deficiencia de vitamina D, osteoporosis, osteomalacia",
        "HEMOGLOBINA GLICOSILADA (GLICOHEMOGLOBINA)" => "Evaluación del control glucémico en pacientes con diabetes mellitus",
        "PERFIL BIOQUIMICO 37" => "Evaluación integral del estado de salud, función hepática, renal y perfil metabólico",
        "INDICE HOMA" => "Medir resistencia a la insulina, riesgo de diabetes tipo 2",
        "PERFIL DE LIPIDOS (CT,NO HDL,INDICE ATEROGENICO,HDL,LDL,VLDL,TG) " => "Evaluar riesgo cardiovascular y dislipidemias",
        "QUIMICA SANGUINEA 6 ELEMENTOS" => "Monitoreo del metabolismo básico, perfil lipídico y función renal",
        "PERFIL HORMONAL 6 (FSH,LH,EST,PROL,PROG,TEST)" => "Evaluación hormonal en fertilidad, hipogonadismo, SOP, menopausia",
        "INSULINA" => "Evaluación de resistencia a la insulina, diagnóstico de hipoglucemia",
        "QUIMICA SANGUINEA 50" => "Evaluación general de salud metabólica, hepática, renal y electrolítica",
        "PERFIL TIROIDEO (T-UPTAKE,FT7,T3,T3RU,T4,FT4,TSH,IPROT)" => "Diagnóstico de enfermedades tiroideas (hipotiroidismo, hipertiroidismo, autoinmunidad)",
        "UROCULTIVO" => "Diagnóstico preciso de infecciones del tracto urinario",
        "EXAMEN GENERAL DE ORINA" => "Detección de infecciones urinarias y evaluación renal/metabólica",
        "TIEMPO DE TROMBOPLASTINA PARCIAL (TTP)" => "Monitoreo de anticoagulantes, diagnóstico de coagulopatías",
        "GLUCOSA, CURVA DE TOLERANCIA 2 HRS (75gr)" => "Diagnóstico de diabetes tipo 2 y diabetes gestacional",
        "PERFIL HEPATICO (PRUEBA DE FUNCION HEPATICO)" => "Evaluación de hepatitis, cirrosis, monitoreo de fármacos hepatotóxicos",
        "ECO DE MAMA BILATERAL" => "Detección de masas, quistes, estudio de mama densa o sospecha de cáncer",
        "ACIDO URICO EN ORINA" => "Diagnóstico y seguimiento de gota, cálculos renales, control con alopurinol",
        "ANFETAMINAS EN ORINA" => "Detección de abuso o uso clínico de estimulantes, pruebas laborales",
        "MAGNESIO SERICO" => "Evaluación de hipomagnesemia, enfermedades renales, alcoholismo",
        "NITROGENO DE LA UREA EN ORINA" => "Evaluar metabolismo proteico, balance nitrogenado, función renal",
        "NITROGENO DE LA UREA EN ORINA DE 24 HORAS" => "Balance nitrogenado, catabolismo, estado nutricional",
        "NITROGENO DE LA UREA EN SANGRE" => "Evaluación de función renal, deshidratación, sangrado digestivo",
        "OPIACEOS EN ORINA" => "Control clínico o legal del consumo de opioides",
        "OXIUROS (NIH), PRUEBA DE:" => "Diagnóstico de oxiuriasis (enterobiasis) en niños y adultos",
        "PANEL DE DROGAS (ANTIDOPING EN ORINA)" => "Evaluación toxicológica en contextos laborales, médicos o legales",
        "PH URINARIO" => "Diagnóstico de cálculos, infecciones urinarias, acidosis metabólica",
        "ANTICUERPOS ANTI DENGUE IGG  IGM" => "Diagnóstico de dengue agudo o previo, especialmente después del 5º día",
        "PLAQUETAS (RECUENTO PLAQUETARIO) CUENTA DE:" => "Detección de trombocitopenia/trombocitosis, trastornos hemorrágicos o trombóticos",
        "POTASIO EN ORINA" => "Evaluación de hipopotasemia o hiperpotasemia, control electrolítico",
        "POTASIO EN ORINA DE 24 HORAS" => "Diagnóstico de desequilibrio ácido-base, hiperaldosteronismo",
        "POTASIO SERICO" => "Evaluación de electrolitos, control en insuficiencia renal o uso de diuréticos",
        "PROGESTERONA" => "Confirmar ovulación, evaluar función del cuerpo lúteo, embarazo",
        "PROLACTINA" => "Diagnóstico de galactorrea, infertilidad, prolactinoma",
        "PROTEINA C REACTIVA" => "Detección de inflamación, infecciones agudas o riesgo cardiovascular",
        "PROTEINAS TOTALES" => "Evaluación de nutrición, función hepática o inmunológica",
        "PRUEBA RAPIDA DE INFLUENZA A y B" => "Diagnóstico rápido de infección por virus influenza",
        "QUIMICA SANG.(GLUC.UREA,CREA.A.URICO)" => "Evaluación del metabolismo y función renal",
        "QUIMICA SANGUINEA 5(GLUCOSA,UREA,CREATININA,COLES)" => "Evaluación del metabolismo de lípidos y función renal",
        "R.P.R." => "Tamizaje para sífilis activa",
        "REACCIONES FEBRILES (COMPLETAS)" => "Diagnóstico de enfermedades infecciosas con fiebre",
        "RECUENTO CELULAR EN LIQUIDOS" => "Diagnóstico de infecciones, inflamación o neoplasias",
        "PLAQUETAS" => "Detección de trombocitopenia o trombocitosis",
        "RETICULOCITOS" => "Evaluación de producción de eritrocitos en médula ósea",
        "SANGRE OCULTA EN HECES (GUAYACO)" => "Detección de sangrado gastrointestinal oculto",
        "SERIE BLANCA (LEUCOCITOS Y DIFERENCIAL)" => "Diagnóstico de infecciones, alergias y leucemias",
        "SERIE ROJA (HB,HCTO,VCM,HCM,CMHC)" => "Clasificación y diagnóstico de anemias",
        "SODIO EN ORINA" => "Evaluación del equilibrio electrolítico y función renal",
        "ANTIDOPING INDUSTRIAL 3(COCA, CANA, ANF)" => "Evaluación toxicológica en contextos laborales o clínicos",
        "SODIO EN ORINA DE 24 HORAS" => "Diagnóstico de alteraciones renales o de equilibrio hídrico",
        "SODIO SERICO" => "Diagnóstico de desequilibrios electrolíticos",
        "STORCH IgG (TOXO, RUBE, CITO, HERPES,S/F)" => "Evaluación de inmunidad frente a infecciones congénitas",
        "STORCH IgM (TOXO, RUBE, CITO, HERPES,S/F)" => "Detección de infecciones activas con riesgo fetal",
        "T4 CAPTACION" => "Evaluación de disponibilidad de T4, función tiroidea",
        "T4 LIBRE (TIROXINA LIBRE)" => "Diagnóstico de hipo e hipertiroidismo",
        "TESTOSTERONA TOTAL" => "Evaluación de disfunción hormonal, infertilidad, desarrollo sexual",
        "TIEMPO DE COAGULACION" => "Detección de trastornos hemorrágicos, monitoreo anticoagulación",
        "TIEMPO DE PROTROMBINA" => "Evaluación de coagulación, terapia anticoagulante, función hepática",
        "TIEMPO DE PROTROMBINA CORREGIDO CON PLASMA NORMAL" => "Diferenciar deficiencia de factores vs. inhibidores de coagulación",
        "ANTIESTREPTOLISINAS (ASO)" => "Diagnóstico de fiebre reumática o glomerulonefritis postestreptocócica",
        "TIEMPO DE SANGRADO" => "Evaluar función plaquetaria y hemostasia primaria",
        "TIEMPO DE TROMBOPLASTINA PARCIAL CORREGIDO PLASMAN" => "Diferenciar deficiencia de factores vs. inhibidores de coagulación",
        "TINTA CHINA" => "Diagnóstico de meningitis criptocócica",
        "TIROXINA TOTAL (T4 TOT)" => "Diagnóstico de trastornos tiroideos",
        "CLOSTRIDIUM DIFFICILE TOXINA A y B" => "Diagnóstico de infección por Clostridioides difficile",
        "TRANSAMINASA GLUTAMICO OXALACETICA (AST)" => "Evaluación de daño hepático, hepatitis, cirrosis",
        "TRANSAMINASA GLUTAMICO PIRUVICA (ALT)" => "Evaluación de daño hepático, hepatitis, cirrosis",
        "TRIGLICERIDOS" => "Evaluación del riesgo cardiovascular y metabolismo de lípidos",
        "TSH NEONATAL" => "Detección de hipotiroidismo congénito",
        "ANTIGENO PROSTATICO ESPECIFICO" => "Detección de cáncer de próstata y enfermedades prostáticas",
        "V.D.R.L. (PRUEBAS LUETICAS)" => "Diagnóstico de sífilis activa y seguimiento de tratamiento",
        "VELOCIDAD DE SEDIMENTACION GLOBULAR ( VSG )" => "Indicador de inflamación, infecciones, enfermedades crónicas",
        "WRIGHT, TINCION DE" => "Diagnóstico de leucemias, anemias y parasitosis",
        "POOL DE PROLACTINA" => "Diagnóstico de hiperprolactinemia, tumores hipofisarios",
        "CURVA DE TOLERANCIA A LA GLUCOSA 3 HRS." => "Diagnóstico de diabetes mellitus e intolerancia a la glucosa",
        "Ag. HELICOBACTER PYLORI EN HECES" => "Diagnóstico de infección gástrica activa por H. pylori",
        "Ac. ANTI HEPATITIS C" => "Tamizaje de hepatitis C y necesidad de pruebas confirmatorias",
        "BALANCE DE NITROGENO EN ORINA DE 24 HORAS" => "Evaluación del equilibrio proteico y función renal",
        "ANTICUERPOS ANTI HIV 1 Y 2" => "Detección y diferenciación de infecciones por VIH-1 y VIH-2",
        "Ac. ANTI HEPATITIS A IgG e IgM" => "Detección de infección aguda o pasada por hepatitis A y evaluación de inmunidad",
        "Ac. ANTI HEPATITIS A IgG" => "Evaluación de inmunidad a largo plazo contra hepatitis A",
        "Ac. ANTI HEPATITIS A IgM" => "Diagnóstico de infección aguda reciente por hepatitis A",
        "Ag. SUPERFICIE HEPATITIS B (HBsAg)" => "Detección de infección activa por hepatitis B y evaluación de contagiosidad",
        "COPROCULTIVO" => "Diagnóstico de infecciones gastrointestinales",
        "CULTIVO ORDINARIO CUALQUIER SITIO (AEROBIO)" => "Identificación de infecciones y determinación de sensibilidad a antibióticos",
        "BENZODIACEPINA EN ORINA" => "Monitoreo del uso terapéutico o indebido de benzodiacepinas",
        "CURVA DE INSULINA (5 DETERMINACIONES)" => "Evaluación de resistencia a la insulina y función pancreática",
        "CURVA DE INSULINA (2 DETERMINACIONES)" => "Diagnóstico de resistencia a la insulina",
        "CURVA DE INSULINA (3 DETERMINACIONES)" => "Detección de hiperinsulinemia y trastornos metabólicos",
        "CURVA DE INSULINA (4 DETERMINACIONES)" => "Diagnóstico de alteraciones en la secreción de insulina",
        "ROTAVIRUS EN HECES" => "Diagnóstico de gastroenteritis viral, especialmente en pediatría",
        "GASOMETRIA VENOSA COMPLETA" => "Evaluación de estado ácido-base y función respiratoria",
        "GASOMETRIA ARTERIAL COMPLETA" => "Diagnóstico de acidosis, alcalosis y eficiencia respiratoria",
        "PANEL DE DROGAS (6 DROGAS)" => "Detección de consumo de drogas en ámbitos clínicos, laborales o legales",
        "HEMOGRAMA" => "Evaluación general de salud, anemia, infecciones y trastornos hematológicos",
        "BILIRRUBINAS (TOTAL,DIRECTA E IND.)" => "Evaluación de función hepática y diagnóstico de ictericia",
        "ACIDO URICO EN ORINA DE 24 HORAS" => "Detección de gota, riesgo de litiasis renal, control metabólico",
        "BRUCELLA ANTICUERPOS (REACCION DE HUDLESSON)" => "Diagnóstico serológico de brucelosis aguda",
        "BRUCELLA SP,ROSA BENGALA" => "Tamizaje sensible para brucelosis",
        "CALCIO EN ORINA" => "Detección de hipercalciuria y trastornos metabólicos",
        "CALCIO EN ORINA DE 24 HORAS" => "Evaluación de metabolismo óseo y renal",
        "CALCIO SERICO" => "Diagnóstico de alteraciones óseas, renales y paratiroideas",
        "PRUEBA RAPIDA DE INFLUENZA A/B/A(H1N1)" => "Diagnóstico rápido de infección por influenza",
        "CANNABINOIDES EN ORINA (MARIHUANA)" => "Detección de consumo reciente de marihuana",
        "EXAMEN DE ORINA ULTRASENSIBLE" => "Detección temprana de enfermedades renales y metabólicas",
        "QUIMICA SANGUINEA 28 ELEMENTOS" => "Evaluación integral del estado metabólico, hepático y renal",
        "(FIT) SANGRE OCULTA EN HECES" => "Cribado de cáncer colorrectal, detección de sangrado digestivo oculto",
        "CANDIDINA, PRUEBA CUTANEA" => "Evaluación de inmunidad celular, diagnóstico de candidiasis",
        "CANDITEST ( ANTIGENO DE CANDIDA )" => "Diagnóstico de candidiasis en pacientes inmunodeprimidos",
        "CETONAS EN SANGRE U ORINA (CUERPOS CETONICOS)" => "Detección de cetoacidosis diabética o monitoreo de dieta cetogénica",
        "ACIDO URICO EN SANGRE" => "Diagnóstico de gota, control de función renal",
        "CITOLOGIA DE MOCO FECAL" => "Evaluación de enfermedad intestinal inflamatoria o infecciones",
        "CLORO EN ORINA" => "Evaluación de trastornos ácido-base y balance hidroelectrolítico",
        "CLORO EN SANGRE" => "Diagnóstico de desórdenes electrolíticos o metabólicos",
        "COCAINA EN ORINA" => "Detección de consumo reciente de cocaína",
        "COCCIDIOIDINA,PRUEBA CUTANEA A LA:" => "Diagnóstico de coccidioidomicosis en zonas endémicas",
        "COLESTEROL TOTAL" => "Evaluación de riesgo cardiovascular y control de dislipidemias",
        "COOMBS DIRECTO" => "Diagnóstico de anemia hemolítica autoinmune o reacciones transfusionales",
        "COOMBS INDIRECTO" => "Pruebas de compatibilidad sanguínea, riesgo de enfermedad hemolítica del recién nacido",
        "AGLUTININAS FRIAS (CRIOAGLUTININAS)" => "Diagnóstico de anemia hemolítica por crioaglutininas",
        "COPROPARASITOSCOPICO (UNICA MUESTRA)" => "Diagnóstico de infecciones parasitarias intestinales",
        "CREATIN FOSFOQUINASA TOTAL" => "Evaluación de daño muscular o infarto agudo de miocardio",
        "CREATININA EN ORINA" => "Evaluación de la función renal y tasa de filtración glomerular",
        "CREATININA SERICA" => "Monitoreo de la función renal",
        "CRIOGLOBULINAS EN SUERO  (aglutininas)" => "Diagnóstico de crioglobulinemia y enfermedades autoinmunes",
        "BHCG CORIONICA CUANTITATIVA EN SANGRE" => "Confirmación y monitoreo de embarazo, embarazos ectópicos",
        "CUERPOS GRASOS EN ORINA" => "Indicador de síndrome nefrótico",
        "DENSIDAD EN LIQUIDO (GRAVEDAD ESPECIFICA)" => "Evaluación de concentración de líquidos corporales (ej. orina, plasma)",
        "DENSIDAD URINARIA (GRAVEDAD ESPECIFICA)" => "Diagnóstico de trastornos renales y estado de hidratación",
        "DEPURACION DE CREATININA" => "Evaluación de la función renal y tasa de filtración glomerular",
        "ALBUMINA EN SANGRE" => "Indicador de estado nutricional, función hepática y renal",
        "DESHIDROGENASA LACTICA EN SUERO (LDH)" => "Diagnóstico de daño tisular (cardiaco, hepático, oncológico)",
        "DIMERO D" => "Diagnóstico de trombosis, embolia pulmonar o CID",
        "ELECTROLITOS EN ORINA DE 24 HORAS" => "Evaluación de función renal y equilibrio hidroelectrolítico",
        "ELECTROLITOS SERICOS (SODIO,POTASIO Y CLORO)" => "Monitoreo de equilibrio electrolítico y diagnóstico de desórdenes metabólicos",
        "GONADOTROFINA CORIONICA EN ORINA (CUALITATIVA)" => "Detección temprana de embarazo",
        "GONADOTROFINA CORIONICA EN SANGRE (CUALITATIVA)" => "Confirmación temprana de embarazo, detección de embarazos anormales",
        "GRUPO SANGUINEO Y RH" => "Determinación del tipo sanguíneo para transfusiones y prevención de incompatibilidad",
        "PERFIL BIOQUIMICO 12" => "Evaluación de función hepática, renal, lipídica y metabólica",
        "PERFIL BIOQUIMICO 15 (ELECTROLITOS)" => "Chequeo general y evaluación de funciones orgánicas",
        "PERFIL BIOQUIMICO 17 (ELECTROLITOS,CALCIO,FOSFORO" => "Evaluación integral del estado metabólico y mineral",
        "PERFIL BIOQUIMICO 27" => "Evaluación detallada de salud general y función orgánica",
        "COLESTEROL VLDL" => "Evaluación del perfil lipídico y riesgo cardiovascular",
        "PERFIL HORMONAL 3 (FSH,LH,ESTRADIOL)" => "Evaluación de función ovárica e hipófisis",
        "PERFIL HORMONAL 4 (FSH,LH,ESTR,PROL)" => "Diagnóstico de infertilidad, disfunción hormonal",
        "PERFIL HORMONAL 5 (FSH,LH,ESTR,PROL,PROG)" => "Evaluación de fertilidad y función ovárica",
        "DETECCION DEL CORONAVIRUS SARS COV 2 (FARINGEO Y/0   NASOFARINGEO)" => "Diagnóstico preciso de COVID-19 en fase activa",
        "ANTICUERPO IgG SARS-CoV-2 (COVID-19)" => "Detección de infección pasada o respuesta a vacunación COVID-19",
        "ANTIGENO COVID ( COVID-19 AG)" => "Diagnóstico rápido de infección activa por COVID-19",
        "Ac. IgG ANTI SARS-CoV-2 POST VACUNA/ENFERMEDAD " => "Evaluación de inmunidad postvacunal o postinfecciosa"
    ];

    public $featureLists = [
        [
            'ids' => [4936, 4938, 4940, 4942, 4944,],
            'features' => [
                "Biometría hemática",
                "Química sanguínea de 3 elementos: glucosa, colesterol, triglicéridos",
                "Examen general de orina"
            ]
        ],
        [
            'ids' => [4935, 4937, 4939, 4941, 4943],
            'features' => [
                "Biometría hemática",
                "Química sanguínea de 6 elementos: urea, creatinina, ácido úrico, glucosa, colesterol, triglicéridos",
                "Examen general de orina"
            ]
        ],
    ];

    public $newPackages = [
        [
            'name' => "CHECKUP MUJER MAYOR DE 40",
            "brand_codes" => [
                'swisslab' => 128689,
                'liacsa' => 128698,
                'olab' => 128750,
                'azteca' => 128703,
                'jenner' => 128707,
            ],
            "feature_list" => [
                "BIOMETRÍA HEMÁTICA COMPLETA",
                "RX MAMOGRAFÍA BILATERAL",
                "EXAMEN GENERAL DE ORINA",
                "GLUCOSA EN SANGRE",
                "COLESTEROL TOTAL",
                "TRIGLICÉRIDOS",
                "PAPANICOLAOU (CITOLOGÍ VAGINAL)",
            ],
            "public_price_cents" => 158400,
            "famedic_price_cents" => 94900,
        ],
        [
            'name' => "CHECKUP MUJER MENOR DE 40",
            "brand_codes" => [
                'swisslab' => 128690,
                'liacsa' => 128699,
                'olab' => 128751,
                'azteca' => 128704,
                'jenner' => 128708,
            ],
            "feature_list" => [
                "BIOMETRÍA HEMÁTICA COMPLETA",
                "ECO DE MAMA BILATERAL",
                "EXAMEN GENERAL DE ORINA",
                "GLUCOSA EN SANGRE",
                "COLESTEROL TOTAL",
                "TRIGLICÉRIDOS",
                "PAPANICOLAOU (CITOLOGÍA VAGINAL)"
            ],
            "public_price_cents" => 222900,
            "famedic_price_cents" => 103900,
        ],
        [
            'name' => "CHECKUP PARA EL HOMBRE",
            "brand_codes" => [
                'swisslab' => 128691,
                'liacsa' => 128700,
                'olab' => 128753,
                'azteca' => 128705,
                'jenner' => 128709,
            ],
            "feature_list" => [
                "BIOMETRÍA HEMÁTICA COMPLETA",
                "ANTÍGENO PROSTÁTICO ESPECÍFICO",
                "EXAMEN GENERAL DE ORINA",
                "GLUCOSA EN SANGRE",
                "COLESTEROL TOTAL",
                "TRIGLICÉRIDOS",
            ],
            "public_price_cents" => 168400,
            "famedic_price_cents" => 73900,
        ],
        [
            'name' => "CHECKUP PERFIL TIROIDEO",
            "brand_codes" => [
                'swisslab' => 128694,
                'liacsa' => 128701,
                'olab' => 128696,
                'azteca' => 128759,
                'jenner' => 128710,
            ],
            "feature_list" => [
                "BIOMETRÍA HEMÁTICA COMPLETA",
                "PERFÍL TIROIDEO (T-UPTAKE,FT7,T3,T3RU,T4,FT4,TSH IPROT)",
                "EXÁMEN GENERAL DE ORINA",
                "PAPANICOLAOU (CITOLOGÍA VAGINAL)",
            ],
            "public_price_cents" => 177200,
            "famedic_price_cents" => 78900,
        ],
        [
            'name' => "CHECKUP PERFIL DIABÉTICO",
            "brand_codes" => [
                'swisslab' => 128695,
                'liacsa' => 128702,
                'olab' => 128697,
                'azteca' => 128760,
                'jenner' => 128711,
            ],
            "feature_list" => [
                "BIOMETRÍA HEMÁTICA COMPLETA",
                "HEMOGLOBINA GLICOLISADA (GLICOHEMOGLOBINA)",
                "EXAMEN GENERAL DE ORINA",
                "GLUCOSA EN SANGRE",
                "COLESTEROL TOTAL",
                "TRIGLICÉRIDOS"
            ],
            "public_price_cents" => 155400,
            "famedic_price_cents" => 67900,
        ],
    ];

    public function up(): void
    {
        // 0. Asegurar que la tabla tiene columna slug
        if (Schema::hasTable('laboratory_test_categories') && 
            !Schema::hasColumn('laboratory_test_categories', 'slug')) {
            Schema::table('laboratory_test_categories', function (Blueprint $table) {
                $table->string('slug')->nullable()->after('name');
            });
            Log::info("Added slug column to laboratory_test_categories");
        }

        // 0. PRIMERO: Verificar/Crear la categoría con ID 12
        $categoryId = 12;
        $categoryExists = DB::table('laboratory_test_categories')
            ->where('id', $categoryId)
            ->exists();
        
        if (!$categoryExists) {
            // Crear la categoría con ID 12
            DB::table('laboratory_test_categories')->insert([
                'id' => $categoryId,
                'name' => 'Checkups',
                'slug' => 'checkups', // Añade si tu tabla tiene slug
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Log::info("Created laboratory_test_category with ID: {$categoryId}");
        }

        // 1. CORREGIDO: Solo un Schema::table, no anidado
        Schema::table('laboratory_tests', function (Blueprint $table) {
            // Solo agregar description si no existe
            if (!Schema::hasColumn('laboratory_tests', 'description')) {
                $table->text('description')->nullable()->after('name');
            }
            
            // Solo agregar feature_list si no existe
            if (!Schema::hasColumn('laboratory_tests', 'feature_list')) {
                $table->json('feature_list')->nullable()->after('description');
            }
            
            // Hacer indications nullable si existe
            // Nota: Para usar change() necesitas doctrine/dbal
            if (Schema::hasColumn('laboratory_tests', 'indications')) {
                $table->text('indications')->nullable()->change();
            }
        });

        // 2. Update descriptions based on $tests
        foreach ($this->tests as $name => $description) {
            $affected = DB::table('laboratory_tests')
                ->where('name', $name)
                ->update(['description' => $description]);
            if ($affected === 0) {
                Log::info("No laboratory_tests record found for name: {$name}");
            }
        }

        // 3. Assign featureLists to feature_list column for given ids
        foreach ($this->featureLists as $featureList) {
            foreach ($featureList['ids'] as $id) {
                // Verificar que el registro exista antes de actualizar
                $exists = DB::table('laboratory_tests')
                    ->where('id', $id)
                    ->exists();
                
                if ($exists) {
                    DB::table('laboratory_tests')
                        ->where('id', $id)
                        ->update(['feature_list' => json_encode($featureList['features'])]);
                } else {
                    Log::warning("Laboratory test with ID {$id} not found for feature_list update");
                }
            }
        }

        // 4. Create new laboratory_tests records for newPackages (one per brand)
        if (app()->environment() !== 'testing') {
            foreach ($this->newPackages as $pkg) {
                foreach ($pkg['brand_codes'] as $brand => $gda_id) {
                    // Verificar si ya existe esta combinación
                    $exists = DB::table('laboratory_tests')
                        ->where('name', $pkg['name'])
                        ->where('brand', $brand)
                        ->where('gda_id', $gda_id)
                        ->exists();
                    
                    if (!$exists) {
                        // Verificar nuevamente que la categoría existe
                        $categoryCheck = DB::table('laboratory_test_categories')
                            ->where('id', $categoryId)
                            ->exists();
                        
                        if (!$categoryCheck) {
                            Log::error("Category ID {$categoryId} does not exist. Cannot insert record for: {$pkg['name']}");
                            continue; // Saltar este registro
                        }
                        
                        try {
                            DB::table('laboratory_tests')->insert([
                                'name' => $pkg['name'],
                                'feature_list' => json_encode($pkg['feature_list']),
                                'public_price_cents' => $pkg['public_price_cents'],
                                'famedic_price_cents' => $pkg['famedic_price_cents'],
                                'laboratory_test_category_id' => $categoryId,
                                'brand' => $brand,
                                'gda_id' => $gda_id,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            Log::info("Inserted new package: {$pkg['name']} for brand: {$brand}");
                        } catch (\Exception $e) {
                            Log::error("Failed to insert package {$pkg['name']} for brand {$brand}: " . $e->getMessage());
                        }
                    } else {
                        Log::info("Package {$pkg['name']} for brand {$brand} already exists, skipping.");
                    }
                }
            }
        }

        // 5. Actualizar nombres de chequeos existentes
        LaboratoryTest::where('name', 'Chequeo General Plus')->update([
            'indications' => null,
            'name' => "CHECKUP GENERAL PLUS"
        ]);

        LaboratoryTest::where('name', 'Chequeo General Esencial')->update([
            'indications' => null,
            'name' => "CHECKUP GENERAL ESENCIAL"
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Para el rollback, eliminar los nuevos registros insertados
        foreach ($this->newPackages as $pkg) {
            foreach ($pkg['brand_codes'] as $brand => $gda_id) {
                DB::table('laboratory_tests')
                    ->where('name', $pkg['name'])
                    ->where('brand', $brand)
                    ->where('gda_id', $gda_id)
                    ->delete();
            }
        }
        
        // Revertir los nombres actualizados
        LaboratoryTest::where('name', 'CHECKUP GENERAL PLUS')->update([
            'name' => 'Chequeo General Plus'
        ]);
        
        LaboratoryTest::where('name', 'CHECKUP GENERAL ESENCIAL')->update([
            'name' => 'Chequeo General Esencial'
        ]);
        
        // NOTA: No removemos las columnas description y feature_list
        // para evitar pérdida de datos. Si necesitas removerlas,
        // crea una migración separada.
    }
};
