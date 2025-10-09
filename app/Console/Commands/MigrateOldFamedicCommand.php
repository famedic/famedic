<?php

namespace App\Console\Commands;

use App\Actions\OnlinePharmacy\FetchOrderAction;
use App\Models\Administrator;
use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\LaboratoryPurchase;
use App\Models\OdessaAfiliateAccount;
use App\Models\OnlinePharmacyPurchase;
use App\Models\RegularAccount;
use App\Models\User;
use App\Models\VendorPayment;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Propaganistas\LaravelPhone\PhoneNumber;

class MigrateOldFamedicCommand extends Command
{
    protected $signature = 'famedic:migrate';

    protected $description = 'Migrate data from old database to new database';

    protected $tableMap = [
        OdessaAfiliateAccount::class => 'odessa_afiliate_accounts',
        RegularAccount::class        => 'regular_accounts',
        FamilyAccount::class         => 'family_accounts',
    ];
    protected array $paidLaboratoryPurchases = [
        ["gda_order_id" => "122253", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "20218035", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "GZ0L000004", "date" => "28/05/2024", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000005", "date" => "28/05/2024", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000006", "date" => "28/05/2024", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000007", "date" => "28/05/2024", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000008", "date" => "28/05/2024", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000009", "date" => "28/05/2024", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000010", "date" => "28/05/2024", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000011", "date" => "28/05/2024", "method" => "odessa", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000013", "date" => "28/05/2024", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000014", "date" => "28/05/2024", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000015", "date" => "05/07/2024", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000017", "date" => "05/07/2024", "method" => "odessa", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000018", "date" => "05/07/2024", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000019", "date" => "05/07/2024", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000021", "date" => "28/08/2024", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000025", "date" => "28/08/2024", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000026", "date" => "28/08/2024", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000028", "date" => "10/09/2024", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000030", "date" => "11/02/2025", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000031", "date" => "11/02/2025", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000032", "date" => "11/02/2025", "method" => "odessa", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000033", "date" => "11/02/2025", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000034", "date" => "11/02/2025", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000035", "date" => "11/02/2025", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000036", "date" => "11/02/2025", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000053", "date" => "11/02/2025", "method" => "odessa", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000054", "date" => "11/02/2025", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000055", "date" => "11/02/2025", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000056", "date" => "11/02/2025", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000057", "date" => "11/02/2025", "method" => "odessa", "brand" => "olab"],
        ["gda_order_id" => "GZ0L000058", "date" => "11/02/2025", "method" => "stripe", "brand" => "olab"],
        ["gda_order_id" => "HB0L000001", "date" => "12/04/2024", "method" => "odessa", "brand" => "azteca"],
        ["gda_order_id" => "HB0L000002", "date" => "12/04/2024", "method" => "odessa", "brand" => "azteca"],
        ["gda_order_id" => "HB0L000003", "date" => "17/04/2024", "method" => "stripe", "brand" => "azteca"],
        ["gda_order_id" => "HB0L000004", "date" => "28/05/2024", "method" => "stripe", "brand" => "azteca"],
        ["gda_order_id" => "HB0L000006", "date" => "05/07/2024", "method" => "stripe", "brand" => "azteca"],
        ["gda_order_id" => "HB0L000008", "date" => "05/07/2024", "method" => "stripe", "brand" => "azteca"],
        ["gda_order_id" => "HB0L000010", "date" => "05/07/2024", "method" => "stripe", "brand" => "azteca"],
        ["gda_order_id" => "HB0L000012", "date" => "28/08/2024", "method" => "stripe", "brand" => "azteca"],
        ["gda_order_id" => "HB0L000020", "date" => "11/02/2025", "method" => "stripe", "brand" => "azteca"],
        ["gda_order_id" => "HB0L000021", "date" => "11/02/2025", "method" => "stripe", "brand" => "azteca"],
        ["gda_order_id" => "HD0L000002", "date" => "12/11/2023", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000004", "date" => "12/11/2023", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000013", "date" => "15/02/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000014", "date" => "15/02/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000016", "date" => "12/11/2023", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000017", "date" => "12/11/2023", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000019", "date" => "12/11/2023", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000034", "date" => "12/11/2023", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000035", "date" => "12/11/2023", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000036", "date" => "15/02/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000037", "date" => "15/02/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000043", "date" => "15/02/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000046", "date" => "15/02/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000048", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000049", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000050", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000051", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000052", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000053", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000054", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000055", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000056", "date" => "17/04/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000057", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000058", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000060", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000061", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000062", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000063", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000064", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000065", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000066", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000067", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000068", "date" => "12/04/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000069", "date" => "29/04/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000070", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000071", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000072", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000073", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000074", "date" => "28/05/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000075", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000076", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000078", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000079", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000080", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000081", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000082", "date" => "28/05/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000083", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000084", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000085", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000086", "date" => "28/05/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000087", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000088", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000089", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000090", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000091", "date" => "28/05/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000092", "date" => "28/05/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000094", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000095", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000096", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000097", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000098", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000099", "date" => "28/05/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000100", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000101", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000102", "date" => "28/05/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000103", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000104", "date" => "28/05/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000105", "date" => "28/05/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000106", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000107", "date" => "28/05/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000108", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000109", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000110", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000112", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000113", "date" => "28/05/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000114", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000116", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000117", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000118", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000119", "date" => "28/05/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000120", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000121", "date" => "05/07/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000122", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000123", "date" => "05/07/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000124", "date" => "05/07/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000125", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000126", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000127", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000128", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000129", "date" => "05/07/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000130", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000131", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000132", "date" => "05/07/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000133", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000134", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000135", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000136", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000137", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000138", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000139", "date" => "05/07/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000140", "date" => "05/07/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000141", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000142", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000143", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000144", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000145", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000146", "date" => "05/07/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000147", "date" => "05/07/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000148", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000149", "date" => "05/07/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000150", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000151", "date" => "05/07/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000152", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000153", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000154", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000155", "date" => "28/08/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000156", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000158", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000159", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000162", "date" => "28/08/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000163", "date" => "28/08/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000164", "date" => "28/08/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000165", "date" => "28/08/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000166", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000167", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000168", "date" => "28/08/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000169", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000170", "date" => "28/08/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000171", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000172", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000173", "date" => "28/08/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000174", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000175", "date" => "28/08/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000176", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000177", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000178", "date" => "28/08/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000179", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000180", "date" => "28/08/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000181", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000182", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000183", "date" => "28/08/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000184", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000185", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000186", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000187", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000188", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000189", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000190", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000191", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000192", "date" => "28/08/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000193", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000194", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000195", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000196", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000197", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000198", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000199", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000200", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000201", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000202", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000203", "date" => "28/08/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000204", "date" => "28/08/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000205", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000206", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000207", "date" => "28/08/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000208", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000210", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000211", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000212", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000213", "date" => "10/09/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000214", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000215", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000216", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000217", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000218", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000219", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000220", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000221", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000222", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000223", "date" => "10/09/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000224", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000225", "date" => "10/09/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000226", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000227", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000228", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000229", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000230", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000231", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000232", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000233", "date" => "10/09/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000234", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000235", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000238", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000239", "date" => "10/09/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000240", "date" => "10/09/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000241", "date" => "10/09/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000248", "date" => "14/11/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000249", "date" => "14/11/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000250", "date" => "14/11/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000251", "date" => "14/11/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000254", "date" => "14/11/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000255", "date" => "14/11/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000256", "date" => "14/11/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000257", "date" => "14/11/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000259", "date" => "14/11/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000260", "date" => "14/11/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000261", "date" => "14/11/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000262", "date" => "14/11/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000264", "date" => "14/11/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000265", "date" => "14/11/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000266", "date" => "14/11/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000267", "date" => "14/11/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000268", "date" => "14/11/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000269", "date" => "14/11/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000270", "date" => "14/11/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000271", "date" => "14/11/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000272", "date" => "14/11/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000273", "date" => "14/11/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000274", "date" => "14/11/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000275", "date" => "14/11/2024", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000276", "date" => "14/11/2024", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000335", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000336", "date" => "11/02/2025", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000337", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000338", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000339", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000340", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000341", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000342", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000343", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000344", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000345", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000346", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000348", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000349", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000350", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000351", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000352", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000353", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000356", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000358", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000359", "date" => "11/02/2025", "method" => "odessa", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000360", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000361", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000362", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000363", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000364", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000366", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000367", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000368", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000369", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
        ["gda_order_id" => "HD0L000370", "date" => "11/02/2025", "method" => "stripe", "brand" => "swisslab"],
    ];
    // We'll store all FamilyMembers here for a separate pass
    protected array $allFamilyMembers = [];

    public function handle()
    {
        $this->migrateAffiliatedCompanies();
        $this->migrateUsers();
        $this->migrateCustomers();
        $this->migrateAdministrators();
        $this->migrateFamilyMembers();
        $this->migrateLaboratoryConcierges();
        $this->migrateAddresses();
        $this->migrateOnlinePharmacyPurchases();
        $this->migrateLaboratoryPurchases();
        $this->migrateMedicalSubscriptions();
        $this->generateLaboratoryVendorPayments();

        $this->verifyMinimalData();
    }

    protected function migrateAffiliatedCompanies()
    {
        $this->line('Migrating affiliated companies...');

        $companies = DB::connection('mysqlold')
            ->table('odessa_afiliate_members')
            ->whereNotNull('company_id')
            ->distinct()
            ->pluck('company_id')
            ->filter();

        if ($companies->isNotEmpty()) {
            $companiesToInsert = $companies->map(function ($id) {
                return ['odessa_identifier' => $id];
            })->toArray();

            DB::connection('mysql')
                ->table('odessa_afiliated_companies')
                ->insert($companiesToInsert);
        }

        Log::info('Affiliated companies migration completed.');
    }

    protected function migrateUsers()
    {
        Log::info('Migrating users table...');

        $total = DB::connection('mysqlold')
            ->table('users')
            ->count();

        $bar = $this->output->createProgressBar($total);



        DB::connection('mysqlold')
            ->table('users')
            ->orderBy('id')
            ->chunk(100, function ($users) use ($bar) {
                $chunk = [];
                foreach ($users as $user) {
                    $phone = $user->phone ? new PhoneNumber($user->phone) : null;

                    $chunk[] = [
                        'id'                 => $user->id,
                        'name'               => $user->name,
                        'email'              => $user->email,
                        'maternal_lastname'  => $user->maternal_lastname,
                        'paternal_lastname'  => $user->paternal_lastname,
                        'email_verified_at'  => $user->email_verified_at,
                        'phone'              => $phone ? str_replace(' ', '', $phone->formatNational()) : null,
                        'phone_country'      => $phone?->getCountry(),
                        'phone_verified_at'  => $user->phone_verified_at,
                        'birth_date'         => $user->birth_date,
                        'gender'             => $user->sex == 1 ? 1 : 2,
                        'password'           => $user->password,
                        'created_at'         => $user->created_at,
                        'updated_at'         => $user->updated_at,
                    ];

                    $bar->advance();
                }

                DB::connection('mysql')
                    ->table('users')
                    ->insert($chunk);
            });

        $bar->finish();
        $this->newLine();
    }

    /**
     * Migrate Odessa/Regular/Referred customers, but skip FamilyMembers.
     * Family is collected in $this->allFamilyMembers for a separate pass.
     */
    protected function migrateCustomers()
    {
        Log::info('Migrating customers table...');

        $total = DB::connection('mysqlold')
            ->table('customers')
            ->count();

        $bar = $this->output->createProgressBar($total);

        $customerMap = [
            'App\Models\OdessaAfiliateMember' => OdessaAfiliateAccount::class,
            'App\Models\RegularMember'        => RegularAccount::class,
            'App\Models\FamilyMember'         => FamilyAccount::class,
            'App\Models\ReferredMember'       => RegularAccount::class, // We'll treat referred as "regular"
        ];

        // If you have affiliated companies you need to map:
        $companyIdMap = DB::connection('mysql')
            ->table('odessa_afiliated_companies')
            ->pluck('id', 'odessa_identifier')
            ->toArray();

        // We'll hold *all* ReferredMembers in memory across chunks, then insert them last
        $allReferredRows = [];

        DB::connection('mysqlold')
            ->table('customers')
            ->leftJoin('odessa_afiliate_members', function ($join) {
                $join->on('customers.customerable_id', '=', 'odessa_afiliate_members.id')
                    ->where('customers.customerable_type', '=', 'App\Models\OdessaAfiliateMember');
            })
            ->leftJoin('regular_members', function ($join) {
                $join->on('customers.customerable_id', '=', 'regular_members.id')
                    ->where('customers.customerable_type', '=', 'App\Models\RegularMember');
            })
            ->leftJoin('family_members', function ($join) {
                $join->on('customers.customerable_id', '=', 'family_members.id')
                    ->where('customers.customerable_type', '=', 'App\Models\FamilyMember');
            })
            ->leftJoin('referred_members', function ($join) {
                $join->on('customers.customerable_id', '=', 'referred_members.id')
                    ->where('customers.customerable_type', '=', 'App\Models\ReferredMember');
            })
            ->select(
                'customers.id AS customer_id',
                'customers.user_id AS customer_user_id',
                'customers.customerable_type AS customer_type',
                'customers.customerable_id AS customerable_id',
                'customers.medical_attention_id AS old_medical_attention_identifier',
                'customers.stripe_id AS old_stripe_id',
                'customers.created_at AS customer_created_at',
                'customers.updated_at AS customer_updated_at',

                // from odessa_afiliate_members
                'odessa_afiliate_members.company_id AS oam_company_id',
                'odessa_afiliate_members.partner_id AS oam_partner_id',
                'odessa_afiliate_members.odessa_id AS oam_odessa_id',
                'odessa_afiliate_members.medical_attention_subscribed_on AS oam_subscribed_on',

                // from regular_members
                'regular_members.medical_attention_subscribed_on AS rm_subscribed_on',

                // from family_members
                'family_members.name AS fm_name',
                'family_members.paternal_lastname AS fm_paternal_lastname',
                'family_members.maternal_lastname AS fm_maternal_lastname',
                'family_members.kinship AS fm_kinship',
                'family_members.customer_id AS fm_parent_customer_id', // This is the parent's ID in old DB
                // if your table actually has that column

                // from referred_members
                'referred_members.medical_attention_subscribed_on AS ref_subscribed_on'
            )
            ->orderBy('customers.id')
            ->chunk(100, function ($rows) use (
                $bar,
                $customerMap,
                $companyIdMap,
                &$allReferredRows
            ) {
                $customerChunk = [];
                $customerableChunks = [
                    OdessaAfiliateAccount::class => [],
                    RegularAccount::class        => [],
                ];

                foreach ($rows as $row) {
                    if (!$row->customer_id) {
                        throw new \RuntimeException(
                            "Cannot process customer due to missing ID, " .
                                "customerable_type: {$row->customer_type}, " .
                                "customerable_id: {$row->customerable_id}"
                        );
                    }

                    // If it's a RegularMember but there's no user_id, skip it
                    if (
                        $row->customer_type === 'App\Models\RegularMember' &&
                        $row->customer_user_id === null
                    ) {
                        Log::info('Skipping RegularMember without user ID, customer ID: ' . $row->customer_id);
                        $bar->advance();
                        continue;
                    }

                    $newType = $customerMap[$row->customer_type] ?? null;
                    if (!$newType) {
                        throw new \RuntimeException(
                            "Cannot process customer due to missing type: " .
                                "type: {$row->customer_type}, id: {$row->customerable_id}"
                        );
                    }

                    // If ReferredMember => handle after chunk
                    if ($row->customer_type === 'App\Models\ReferredMember') {
                        $allReferredRows[] = $row;
                        $bar->advance();
                        continue;
                    }

                    // If it's FamilyMember => skip insertion, but store in $this->allFamilyMembers
                    if ($newType === FamilyAccount::class) {
                        $this->allFamilyMembers[] = [
                            'user_id'                => $row->customer_user_id,
                            'stripe_id'              => $row->old_stripe_id,
                            'medical_attention_identifier' => $row->old_medical_attention_identifier,
                            'old_family_customer_id' => $row->customer_id,   // this family's "customers.id"
                            // If in "family_members", we had a field 'customer_id' that references the parent's old ID:
                            'old_parent_customer_id' => $row->fm_parent_customer_id ?? null,
                            'name'                   => $row->fm_name,
                            'paternal_lastname'      => $row->fm_paternal_lastname,
                            'maternal_lastname'      => $row->fm_maternal_lastname,
                            'kinship'                => $row->fm_kinship,
                            'created_at'             => $row->customer_created_at,
                            'updated_at'             => $row->customer_updated_at,
                        ];
                        // Skip inserting for now
                        $bar->advance();
                        continue;
                    }

                    // Otherwise handle Odessa/Regular
                    $medicalExpires   = null;
                    $customerableData = [
                        'id'         => $row->customerable_id,
                        'created_at' => $row->customer_created_at,
                        'updated_at' => $row->customer_updated_at,
                    ];

                    if ($newType === OdessaAfiliateAccount::class) {
                        $customerableData['odessa_identifier']  = $row->oam_odessa_id;
                        $customerableData['partner_identifier'] = $row->oam_partner_id;
                        $customerableData['odessa_afiliated_company_id'] =
                            $row->oam_company_id
                            ? ($companyIdMap[$row->oam_company_id] ?? null)
                            : null;
                        $medicalExpires = $row->oam_subscribed_on ? Carbon::parse($row->oam_subscribed_on)->addYear() : null;

                        $customerableChunks[OdessaAfiliateAccount::class][] = $customerableData;
                    } elseif ($newType === RegularAccount::class) {
                        $medicalExpires = $row->rm_subscribed_on ? Carbon::parse($row->rm_subscribed_on)->addYear() : null;
                        $customerableChunks[RegularAccount::class][] = $customerableData;
                    }

                    // Insert record into new "customers" table
                    $customerChunk[] = [
                        'id'          => $row->customer_id,
                        'user_id'     => $row->customer_user_id,
                        'medical_attention_identifier' => $row->old_medical_attention_identifier,
                        'medical_attention_subscription_expires_at' => $medicalExpires,
                        'stripe_id'   => $row->old_stripe_id,
                        'customerable_type' => $newType,
                        'customerable_id'   => $row->customerable_id,
                        'created_at'        => $row->customer_created_at,
                        'updated_at'        => $row->customer_updated_at,
                    ];

                    $bar->advance();
                }

                // Bulk insert Odessa/Regular
                foreach ($customerableChunks as $type => $bulkRecords) {
                    if (!empty($bulkRecords)) {
                        DB::connection('mysql')
                            ->table($this->tableMap[$type])
                            ->insert($bulkRecords);
                    }
                }

                if (!empty($customerChunk)) {
                    DB::connection('mysql')
                        ->table('customers')
                        ->insert($customerChunk);
                }
            });

        // 2) We've now inserted all Odessa/Regular
        //    Let's reset the sequence for regular_accounts
        // DB::connection('mysql')->statement("
        //     SELECT setval(
        //         pg_get_serial_sequence('regular_accounts', 'id'),
        //         COALESCE((SELECT MAX(id) FROM regular_accounts) + 1, 1),
        //         false
        //     )
        // ");

        // 3) Insert ReferredMembers (with new auto IDs in regular_accounts)
        foreach ($allReferredRows as $refRow) {
            $medicalExpires = $refRow->ref_subscribed_on ? Carbon::parse($refRow->ref_subscribed_on)->addYear() : null;

            $newRegularId = DB::connection('mysql')
                ->table($this->tableMap[RegularAccount::class])
                ->insertGetId([
                    'created_at' => $refRow->customer_created_at,
                    'updated_at' => $refRow->customer_updated_at,
                ]);

            // Insert in `customers`, preserving old 'customers.id'
            DB::connection('mysql')
                ->table('customers')
                ->insert([
                    'id'          => $refRow->customer_id,
                    'user_id'     => $refRow->customer_user_id,
                    'medical_attention_identifier' => $refRow->old_medical_attention_identifier,
                    'medical_attention_subscription_expires_at' => $medicalExpires,
                    'stripe_id'   => $refRow->old_stripe_id,
                    'customerable_type' => RegularAccount::class,
                    'customerable_id'   => $newRegularId,
                    'created_at'        => $refRow->customer_created_at,
                    'updated_at'        => $refRow->customer_updated_at,
                ]);
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Now that Odessa/Regular/Referred are migrated, handle FamilyMembers in a separate pass.
     * We stored all raw family data in $this->allFamilyMembers. 
     */
    protected function migrateFamilyMembers()
    {
        Log::info('Migrating family members in a separate pass...');

        if (empty($this->allFamilyMembers)) {
            Log::info("No family members found, skipping.");
            return;
        }

        // We will insert each family row individually so we can retrieve the newly inserted ID 
        // from family_accounts and then create a matching row in customers referencing that ID.
        $insertedCount = 0;

        foreach ($this->allFamilyMembers as $fam) {
            $oldParentId = $fam['old_parent_customer_id'] ?? null;
            if (!$oldParentId) {
                Log::warning("Family row missing old_parent_customer_id, skipping: " . json_encode($fam));
                continue;
            }

            // 1) Look up the old parent's 'customers' row in MySQL
            $oldParentCustomer = DB::connection('mysqlold')
                ->table('customers')
                ->where('id', $oldParentId)
                ->first();

            if (!$oldParentCustomer) {
                Log::warning("No old parent customer found (ID=$oldParentId), skipping child: " . json_encode($fam));
                continue;
            }

            // Parent must have a user_id or we cannot map it properly in the new schema
            // (Because in your new system, the parent's 'customers.user_id' is not null.)
            if (!$oldParentCustomer->user_id) {
                Log::warning("Parent has no user_id? oldParentId=$oldParentId, skipping child.");
                continue;
            }

            // 2) Look up parent's user in old DB => gather parent's email
            $oldParentUser = DB::connection('mysqlold')
                ->table('users')
                ->where('id', $oldParentCustomer->user_id)
                ->first();

            if (!$oldParentUser || !$oldParentUser->email) {
                Log::warning("Old parent's user not found or missing email, parentUserId={$oldParentCustomer->user_id}");
                continue;
            }

            // 3) Find that user in the new DB
            //    (assuming emails are unique and unchanged)
            $newParentUser = DB::connection('mysql')
                ->table('users')
                ->where('email', $oldParentUser->email)
                ->first();

            if (!$newParentUser) {
                Log::warning("No matching user in new DB for parent email={$oldParentUser->email}, skipping child.");
                continue;
            }

            // 4) Find parent's new "customers" row 
            //    If the user can only have 1 "customers" row, we do:
            $newParentCustomer = DB::connection('mysql')
                ->table('customers')
                ->where('user_id', $newParentUser->id)
                ->orderBy('id')
                ->first();

            if (!$newParentCustomer) {
                Log::warning("No new customer found for parent user ID={$newParentUser->id}, skipping child.");
                continue;
            }

            // 5) Create the child's family_accounts row
            //    Here, family_accounts.customer_id references the *parent's* customers.id:
            //    e.g. if Dad is customer #123, each child's family_accounts.customer_id = 123.
            $newFamilyAccountId = DB::connection('mysql')
                ->table('family_accounts')
                ->insertGetId([
                    // If you want to preserve the old family_members.id => 
                    // 'id' => $fam['old_family_member_id'], // if you kept that separately
                    'customer_id'       => $newParentCustomer->id,  // the parent's customer ID
                    'name'              => $fam['name'],
                    'paternal_lastname' => $fam['paternal_lastname'],
                    'maternal_lastname' => $fam['maternal_lastname'],
                    'kinship'           => $fam['kinship'],
                    'created_at'        => $fam['created_at'],
                    'updated_at'        => $fam['updated_at'],
                ]);

            // 6) Now also insert a "customers" row for this child, referencing the newly inserted family_accounts:
            //    Polymorphic fields: customerable_type = FamilyAccount::class, customerable_id = $newFamilyAccountId
            //    Typically there's no user for a child => user_id = null
            //    If you want to preserve the old 'customers.id' from MySQL for each child, do `'id' => $fam['old_family_customer_id']`
            DB::connection('mysql')
                ->table('customers')
                ->insert([
                    // If preserving old child IDs:
                    'id'          => $fam['old_family_customer_id'], // or omit to let it autoincrement
                    'user_id'     => $fam['user_id'],
                    'medical_attention_identifier' => $fam['medical_attention_identifier'],
                    'medical_attention_subscription_expires_at' => $newParentCustomer->medical_attention_subscription_expires_at,
                    'stripe_id'   => $fam['stripe_id'],
                    'customerable_type' => \App\Models\FamilyAccount::class,
                    'customerable_id'   => $newFamilyAccountId,
                    'created_at'        => $fam['created_at'],
                    'updated_at'        => $fam['updated_at'],
                ]);

            $insertedCount++;
        }

        Log::info("Finished inserting $insertedCount family members (with their matching customer row).");
    }

    protected function migrateAdministrators()
    {
        Log::info('Migrating administrators...');

        $total = DB::connection('mysqlold')->table('administrators')->count();
        $bar = $this->output->createProgressBar($total);

        DB::connection('mysqlold')
            ->table('administrators')
            ->orderBy('id')
            ->chunk(100, function ($admins) use ($bar) {
                $chunk = [];
                foreach ($admins as $admin) {
                    $chunk[] = [
                        'id'         => $admin->id,
                        'user_id'    => $admin->user_id,
                        'created_at' => $admin->created_at,
                        'updated_at' => $admin->updated_at,
                    ];

                    $bar->advance();
                }

                DB::connection('mysql')
                    ->table('administrators')
                    ->insert($chunk);
            });

        foreach (Administrator::all() as $admin) {
            $admin->assignRole('Administrador');
        }

        $bar->finish();
        $this->newLine();
    }

    protected function migrateLaboratoryConcierges()
    {
        Log::info('Migrating laboratory concierges...');

        $activeConciergeIds = array_unique(DB::connection('mysqlold')->table('laboratory_appointment_confirmations')->select()->whereNotNull('laboratory_concierge_id')->pluck('laboratory_concierge_id')->toArray());

        $total = sizeof($activeConciergeIds);
        $bar = $this->output->createProgressBar($total);

        DB::connection('mysqlold')
            ->table('laboratory_concierges')
            ->orderBy('id')
            ->whereIn('id', $activeConciergeIds)
            ->chunk(100, function ($concierges) use ($bar) {
                $chunk = [];
                foreach ($concierges as $concierge) {
                    $chunk[] = [
                        'id'         => $concierge->id,
                        'administrator_id'    => User::findOrFail($concierge->user_id)->administrator->id,
                        'created_at' => $concierge->created_at,
                        'updated_at' => $concierge->updated_at,
                    ];

                    $bar->advance();
                }

                DB::connection('mysql')
                    ->table('laboratory_concierges')
                    ->insert($chunk);
            });

        $bar->finish();
        $this->newLine();
    }

    protected function migrateAddresses()
    {
        $total = DB::connection('mysqlold')->table('shipping_addresses')->whereIn('customer_id', array_unique(Customer::pluck('id')->toArray()))->count();
        $bar = $this->output->createProgressBar($total);

        DB::connection('mysqlold')
            ->table('shipping_addresses')
            ->where('state', 'Queretaro')
            ->update([
                'state' => 'Querétaro'
            ]);

        DB::connection('mysqlold')
            ->table('shipping_addresses')
            ->orderBy('id')
            ->whereIn('customer_id', array_unique(Customer::pluck('id')->toArray()))
            ->chunk(100, function ($addresses) use ($bar) {
                $chunk = [];
                foreach ($addresses as $address) {
                    if (!array_key_exists($address->state, config('mexicanstates')) || !in_array($address->municipality, config('mexicanstates.' . $address->state))) {
                        continue;
                    }

                    $chunk[] = [
                        'id' => $address->id,
                        'street' => $address->street,
                        'number' => $address->street_number,
                        'neighborhood' => $address->neighborhood,
                        'state' => $address->state,
                        'city' => $address->municipality,
                        'zipcode' => $address->postal_code,
                        'additional_references' => $address->references,
                        'customer_id' => $address->customer_id
                    ];

                    $bar->advance();
                }

                DB::connection('mysql')
                    ->table('addresses')
                    ->insert($chunk);
            });

        $bar->finish();
        $this->newLine();
    }

    protected function migrateOnlinePharmacyPurchases()
    {
        $total = DB::connection('mysqlold')
            ->table('online_pharmacy_purchases')
            ->count();

        $bar = $this->output->createProgressBar($total);

        DB::connection('mysqlold')
            ->table('online_pharmacy_purchases')
            ->orderBy('id')
            ->chunk(10, function ($purchases) use ($bar) {
                $purchaseChunk = [];
                $purchaseItems = [];
                $transactions = [];
                $transactionables = [];

                foreach ($purchases as $purchase) {
                    $orderDetails = app(FetchOrderAction::class)($purchase->vitau_order_id);

                    try {
                        // 1. Get phone data
                        $phoneData = !empty($orderDetails['patient']['user']['phone'])
                            ? $orderDetails['patient']['user']['phone']
                            : DB::connection('mysqlold')
                            ->table('customers')
                            ->join('users', 'customers.user_id', '=', 'users.id')
                            ->where('customers.id', $purchase->customer_id)
                            ->select('users.phone')
                            ->first()
                            ->phone;

                        $phone = new PhoneNumber($phoneData);

                        // 2. Build the purchase items array
                        //    (Notice we add 'vitau_order_id' => $purchase->vitau_order_id)
                        foreach ($orderDetails['details'] as $item) {
                            $purchaseItems[] = [
                                'vitau_order_id'    => $purchase->vitau_order_id, // important for final mapping
                                'vitau_product_id'  => $item['id'],
                                'name'              => $item['product']['base']['name'],
                                'presentation'      => $item['product']['presentation'],
                                'quantity'          => $item['quantity'],
                                'price_cents'       => intval(floatval($item['price']) * 100),
                                'subtotal_cents'    => intval(floatval($item['subtotal']) * 100),
                                'discount_cents'    => intval(floatval($item['discount']) * 100),
                                'tax_cents'         => intval(floatval($item['iva']) * 100),
                                'total_cents'       => intval(floatval($item['total']) * 100),
                                'created_at'        => $purchase->created_at,
                                'updated_at'        => $purchase->updated_at,
                                'deleted_at'        => $purchase->deleted_at,
                            ];
                        }

                        // 3. Build the “purchase” row array
                        $purchaseChunk[] = [
                            'id'                      => $purchase->id,
                            'customer_id'             => $purchase->customer_id,
                            'vitau_order_id'          => $purchase->vitau_order_id,
                            'name'                    => $orderDetails['patient']['user']['first_name'],
                            'paternal_lastname'       => $orderDetails['patient']['user']['last_name'],
                            'maternal_lastname'       => in_array($purchase->id, [44, 49])
                                ? 'Vega'
                                : $orderDetails['patient']['user']['second_last_name'],
                            'phone'                   => str_replace(' ', '', $phone->formatNational()),
                            'phone_country'           => $phone->getCountry(),
                            'street'                  => $orderDetails['shipping']['street'],
                            'number'                  => $orderDetails['shipping']['exterior_number'],
                            'neighborhood'            => $orderDetails['shipping']['neighborhood'],
                            'state'                   => $orderDetails['shipping']['state'],
                            'city'                    => $orderDetails['shipping']['city'],
                            'zipcode'                 => $orderDetails['shipping']['zipcode'],
                            'additional_references'   => $orderDetails['shipping']['additional_info'],
                            'subtotal_cents'          => intval(floatval($orderDetails['subtotal']) * 100),
                            'shipping_price_cents'    => intval(floatval($orderDetails['shipping_price']) * 100),
                            'tax_cents'               => intval(floatval($orderDetails['iva']) * 100),
                            'discount_cents'          => intval(floatval($orderDetails['discount']) * 100),
                            'total_cents'             => intval(floatval($orderDetails['total']) * 100),
                            'expected_delivery_date'  => Carbon::parse($orderDetails['expected_delivery_date']),
                            'created_at'              => $purchase->created_at,
                            'updated_at'              => $purchase->updated_at,
                            'deleted_at'              => $purchase->deleted_at,
                        ];

                        // 4. Get Transactions using transactionable_type and transactionable_id
                        $purchaseTransactions = DB::connection('mysqlold')
                            ->table('transactionables')
                            ->join('transactions', 'transactionables.transaction_id', '=', 'transactions.id')
                            ->where('transactionables.transactionable_type', 'App\\Models\\OnlinePharmacyPurchase')
                            ->where('transactionables.transactionable_id', $purchase->id)
                            ->select('transactions.*')
                            ->get();

                        foreach ($purchaseTransactions as $transaction) {
                            $transactions[] = [
                                'id'                      => $transaction->id,
                                'transaction_amount_cents' => $transaction->transaction_amount,
                                'payment_method'          => $transaction->payment_method,
                                'reference_id'            => $transaction->reference_id,
                                'created_at'              => $transaction->created_at,
                                'updated_at'              => $transaction->updated_at,
                                'deleted_at'              => $transaction->deleted_at,
                            ];

                            $transactionables[] = [
                                'transaction_id'       => $transaction->id,
                                'transactionable_id'   => $purchase->id,
                                'transactionable_type' => OnlinePharmacyPurchase::class,
                            ];
                        }
                    } catch (\Exception $e) {
                        dd($e);
                    }

                    $bar->advance();
                }

                // 4. Insert purchases into PostgreSQL
                DB::connection('mysql')
                    ->table('online_pharmacy_purchases')
                    ->insert($purchaseChunk);

                // 5. Insert transactions into PostgreSQL
                DB::connection('mysql')
                    ->table('transactions')
                    ->insert($transactions);

                // 6. Insert transactionables into PostgreSQL
                DB::connection('mysql')
                    ->table('transactionables')
                    ->insert($transactionables);

                // 5. Build a map from vitau_order_id => newly inserted purchase ID
                $purchaseIdMap = DB::connection('mysql')
                    ->table('online_pharmacy_purchases')
                    ->whereIn('vitau_order_id', array_column($purchaseChunk, 'vitau_order_id'))
                    ->pluck('id', 'vitau_order_id');

                // 6. Loop over purchaseItems and replace the 'vitau_order_id' with the correct 'online_pharmacy_purchase_id'
                foreach ($purchaseItems as $index => $purchaseItem) {
                    $vitauOrderId = $purchaseItem['vitau_order_id'];

                    // now map properly
                    $purchaseItems[$index]['online_pharmacy_purchase_id'] = $purchaseIdMap[$vitauOrderId];

                    // remove the extra 'vitau_order_id' if you don’t want it in your table
                    unset($purchaseItems[$index]['vitau_order_id']);
                }

                // 7. Insert purchase items into PostgreSQL
                DB::connection('mysql')
                    ->table('online_pharmacy_purchase_items')
                    ->insert($purchaseItems);
            });

        $bar->finish();
        $this->newLine();
    }

    protected function migrateLaboratoryPurchases()
    {
        // 1. Count how many old purchases we have
        $total = DB::connection('mysqlold')
            ->table('laboratory_purchases')
            ->whereNotNull('deleted_at')
            ->orWhereNull('deleted_at')
            ->count();

        // Create a progress bar
        $bar = $this->output->createProgressBar($total);

        // 2. Chunk over old purchases
        DB::connection('mysqlold')
            ->table('laboratory_purchases')
            ->orderBy('id')
            ->whereNotNull('deleted_at')
            ->orWhereNull('deleted_at')
            ->chunk(50, function ($oldPurchases) use ($bar) {
                // Arrays for batch inserts
                $purchaseChunk = [];
                $purchaseItems = [];
                $transactionables = [];
                $transactions = [];


                foreach ($oldPurchases as $oldPurchase) {
                    // Fetch the first shipping address for the customer
                    $shippingAddress = DB::connection('mysqlold')
                        ->table('shipping_addresses')
                        ->where('customer_id', $oldPurchase->customer_id)
                        ->first();

                    $phone = new PhoneNumber($shippingAddress->phone, $shippingAddress->phone_country);

                    // Grab the old “items” for this purchase
                    $oldItems = DB::connection('mysqlold')
                        ->table('laboratory_purchase_items')
                        ->where('laboratory_purchase_id', $oldPurchase->id)
                        ->get();

                    // Build the array for the new laboratory_purchase_items
                    foreach ($oldItems as $item) {
                        $purchaseItems[] = [
                            'gda_id'                    => $item->code,
                            'name'                      => $item->name,
                            'indications'               => $item->indications,
                            'price_cents'               => $item->price,
                            'laboratory_purchase_id'    => $oldPurchase->id,
                            'created_at'                => $item->created_at,
                            'updated_at'                => $item->updated_at,
                            'deleted_at'                => $oldPurchase->deleted_at,
                        ];
                    }

                    // Build the purchase row to insert in the new DB
                    $np = [
                        'id'                     => $oldPurchase->id,
                        'brand'                  => $oldPurchase->brand,
                        'gda_order_id'           => $oldPurchase->gda_order_id,
                        'name'                   => $shippingAddress->name,
                        'paternal_lastname'      => $shippingAddress->paternal_lastname,
                        'maternal_lastname'      => $shippingAddress->maternal_lastname,
                        'phone'                  => str_replace(' ', '', $phone->formatNational()),
                        'phone_country'          => $phone->getCountry(),
                        'birth_date'             => $shippingAddress->birth_date,
                        'gender'                 => $shippingAddress->sex,
                        'street'                 => $shippingAddress->street,
                        'number'                 => $shippingAddress->street_number,
                        'neighborhood'           => $shippingAddress->neighborhood,
                        'state'                  => $shippingAddress->state,
                        'city'                   => $shippingAddress->municipality,
                        'zipcode'                => $shippingAddress->postal_code,
                        'additional_references'  => $shippingAddress->references,
                        'total_cents'             => $oldItems->sum('price'),
                        'customer_id'            => $oldPurchase->customer_id,
                        'created_at'             => $oldPurchase->created_at,
                        'updated_at'             => $oldPurchase->updated_at,
                        'deleted_at'             => $oldPurchase->deleted_at,
                    ];

                    // if (in_array($oldPurchase->gda_order_id, $this->vendorPaidLaboratoryPurchases)) {
                    //     $np['vendor_paid_at'] = now();
                    //     $key = array_search($oldPurchase->gda_order_id, $this->vendorPaidLaboratoryPurchases);
                    //     if ($key !== false) {
                    //         unset($this->vendorPaidLaboratoryPurchases[$key]);
                    //     }
                    // }

                    $purchaseChunk[] = $np;

                    $purchaseTransactions = DB::connection('mysqlold')
                        ->table('transactionables')
                        ->join('transactions', 'transactionables.transaction_id', '=', 'transactions.id')
                        ->where('transactionables.transactionable_type', 'App\\Models\\LaboratoryPurchase')
                        ->where('transactionables.transactionable_id', $oldPurchase->id)
                        ->where(function ($query) {
                            $query->whereNull('transactions.deleted_at')
                                ->orWhereNotNull('transactions.deleted_at');
                        })
                        ->select('transactions.*')
                        ->get();

                    foreach ($purchaseTransactions as $transaction) {
                        $transactions[] = [
                            'id'                      => $transaction->id,
                            'transaction_amount_cents' => $transaction->transaction_amount,
                            'payment_method'          => $transaction->payment_method,
                            'reference_id'            => $transaction->reference_id,
                            'created_at'              => $transaction->created_at,
                            'updated_at'              => $transaction->updated_at,
                            'deleted_at'              => $oldPurchase->deleted_at,
                        ];

                        $transactionables[] = [
                            'transaction_id'       => $transaction->id,
                            'transactionable_id'   => $oldPurchase->id,
                            'transactionable_type' => LaboratoryPurchase::class,
                        ];
                    }

                    // Advance progress bar
                    $bar->advance();
                }

                // 6. Insert the chunk of purchases into your new DB
                DB::connection('mysql')
                    ->table('laboratory_purchases')
                    ->insert($purchaseChunk);

                DB::connection('mysql')
                    ->table('transactions')
                    ->insert($transactions);

                DB::connection('mysql')
                    ->table('transactionables')
                    ->insert($transactionables);

                // 10. Insert the items
                DB::connection('mysql')
                    ->table('laboratory_purchase_items')
                    ->insert($purchaseItems);
            });

        $bar->finish();
        $this->newLine();
    }

    protected function generateLaboratoryVendorPayments()
    {
        $vendorPayments = [];
        foreach ($this->paidLaboratoryPurchases as $purchase) {
            $laboratoryPurchase = LaboratoryPurchase::where('gda_order_id', $purchase['gda_order_id'])->sole();
            $file = null;
            switch ($purchase['date']) {
                case '12/11/2023':
                    $file = 'SWISS 7,187.86 - 12 nov 23.png';

                    if (!array_key_exists($purchase['date'], $vendorPayments)) {
                        $vendorPayment =  VendorPayment::create([
                            'paid_at' => Carbon::createFromFormat('d/m/Y', ($purchase['date'])),
                            'proof_of_payment' => 'vendor_payments/' . $file
                        ]);

                        $vendorPayments[$purchase['date']] = $vendorPayment;
                    }

                    $laboratoryPurchase->vendorPayments()->attach($vendorPayments[$purchase['date']]);
                    break;
                case '15/02/2024':
                    $file = 'SWISS 5,644.46 - 15 feb 24.png';

                    if (!array_key_exists($purchase['date'], $vendorPayments)) {
                        $vendorPayment =  VendorPayment::create([
                            'paid_at' => Carbon::createFromFormat('d/m/Y', ($purchase['date'])),
                            'proof_of_payment' => 'vendor_payments/' . $file
                        ]);

                        $vendorPayments[$purchase['date']] = $vendorPayment;
                    }

                    $laboratoryPurchase->vendorPayments()->attach($vendorPayments[$purchase['date']]);
                    break;
                case '12/04/2024':
                    $file = 'ODESSA 19,162 - 12 abr 24.pdf';

                    if (!array_key_exists($purchase['date'], $vendorPayments)) {
                        $vendorPayment =  VendorPayment::create([
                            'paid_at' => Carbon::createFromFormat('d/m/Y', ($purchase['date'])),
                            'proof_of_payment' => 'vendor_payments/' . $file
                        ]);

                        $vendorPayments[$purchase['date']] = $vendorPayment;
                    }

                    $laboratoryPurchase->vendorPayments()->attach($vendorPayments[$purchase['date']]);
                    break;
                case '17/04/2024':
                    $file = 'SWISS 3,598.00 - 17 abr 24.png';

                    if (!array_key_exists($purchase['date'], $vendorPayments)) {
                        $vendorPayment =  VendorPayment::create([
                            'paid_at' => Carbon::createFromFormat('d/m/Y', ($purchase['date'])),
                            'proof_of_payment' => 'vendor_payments/' . $file
                        ]);

                        $vendorPayments[$purchase['date']] = $vendorPayment;
                    }

                    $laboratoryPurchase->vendorPayments()->attach($vendorPayments[$purchase['date']]);
                    break;
                case '29/04/2024':
                    $files = ["SWISS 3,598.00 - 17 abr 24.png", "SWISS 132.52 - 29 abr 24.png"];

                    foreach ($files as $index => $file) {
                        if (!array_key_exists($purchase['date'] . ((string)$index), $vendorPayments)) {
                            $vendorPayment =  VendorPayment::create([
                                'paid_at' => Carbon::createFromFormat('d/m/Y', $index == 0 ? ($purchase['date']) : '17/04/2024'),
                                'proof_of_payment' => 'vendor_payments/' . $file
                            ]);

                            $vendorPayments[$purchase['date'] . ((string)$index)] = $vendorPayment;
                        }

                        $laboratoryPurchase->vendorPayments()->attach($vendorPayments[$purchase['date'] . ((string)$index)]);
                    }
                    break;
                case '28/05/2024':
                    $stripe = [
                        'olab' => 'OLAB 7,021.32 - 28 may 24.png',
                        'azteca' => "AZTECA 110.56 - 28 may 24.png",
                        'swisslab' => "SWISS 27,227.30 - 28 may 24.png"
                    ];

                    $odessa = "ODESSA 6,378 28 may 24.pdf";

                    if ($purchase['method'] == "odessa") {
                        if (!array_key_exists($purchase['date'] . 'odessa', $vendorPayments)) {
                            $vendorPayment =  VendorPayment::create([
                                'paid_at' => Carbon::createFromFormat('d/m/Y', ($purchase['date'])),
                                'proof_of_payment' => 'vendor_payments/' . $odessa
                            ]);

                            $vendorPayments[$purchase['date'] . 'odessa'] = $vendorPayment;
                        }

                        $laboratoryPurchase->vendorPayments()->attach($vendorPayments[$purchase['date'] . 'odessa']);
                    } else {
                        foreach ($stripe as $key => $file) {
                            if (!array_key_exists($purchase['date'] . $key, $vendorPayments)) {
                                $vendorPayment =  VendorPayment::create([
                                    'paid_at' => Carbon::createFromFormat('d/m/Y', ($purchase['date'])),
                                    'proof_of_payment' => 'vendor_payments/' . $file
                                ]);

                                $vendorPayments[$purchase['date'] . $key] = $vendorPayment;
                            }
                        }
                        $laboratoryPurchase->vendorPayments()->attach($vendorPayments[$purchase['date'] . $purchase['brand']]);
                    }
                    break;
                case '05/07/2024':
                    $stripe = [
                        'olab' => 'OLAB 1,149.02 - 5 jul 24.png',
                        'azteca' => "AZTECA 1,344.52 - 5 jul 24.png",
                        'swisslab' => "SWISS 13,100.21 - 5 jul 24.png"
                    ];

                    $odessa = "ODESSA 4,557 - 5 jul 24.pdf";

                    if ($purchase['method'] == "odessa") {
                        if (!array_key_exists($purchase['date'] . 'odessa', $vendorPayments)) {
                            $vendorPayment =  VendorPayment::create([
                                'paid_at' => Carbon::createFromFormat('d/m/Y', ($purchase['date'])),
                                'proof_of_payment' => 'vendor_payments/' . $odessa
                            ]);

                            $vendorPayments[$purchase['date'] . 'odessa'] = $vendorPayment;
                        }

                        $laboratoryPurchase->vendorPayments()->attach($vendorPayments[$purchase['date'] . 'odessa']);
                    } else {
                        foreach ($stripe as $key => $file) {
                            if (!array_key_exists($purchase['date'] . $key, $vendorPayments)) {
                                $vendorPayment =  VendorPayment::create([
                                    'paid_at' => Carbon::createFromFormat('d/m/Y', ($purchase['date'])),
                                    'proof_of_payment' => 'vendor_payments/' . $file
                                ]);

                                $vendorPayments[$purchase['date'] . $key] = $vendorPayment;
                            }
                        }
                        $laboratoryPurchase->vendorPayments()->attach($vendorPayments[$purchase['date'] . $purchase['brand']]);
                    }
                    break;
                case '28/08/2024':
                    $stripe = [
                        'olab' => "OLAB 4,868.71 - 28 ago 24.png",
                        "azteca" => "AZTECA 338.61 - 28 ago 24.png",
                        'swisslab' => "SWISS 29,312.38 - 28 ago 24.png"
                    ];

                    $odessa = "ODESSA 14,511.19 28 ago 24.pdf";

                    if ($purchase['method'] == "odessa") {
                        if (!array_key_exists($purchase['date'] . 'odessa', $vendorPayments)) {
                            $vendorPayment =  VendorPayment::create([
                                'paid_at' => Carbon::createFromFormat('d/m/Y', ($purchase['date'])),
                                'proof_of_payment' => 'vendor_payments/' . $odessa
                            ]);

                            $vendorPayments[$purchase['date'] . 'odessa'] = $vendorPayment;
                        }

                        $laboratoryPurchase->vendorPayments()->attach($vendorPayments[$purchase['date'] . 'odessa']);
                    } else {
                        foreach ($stripe as $key => $file) {
                            if (!array_key_exists($purchase['date'] . $key, $vendorPayments)) {
                                $vendorPayment =  VendorPayment::create([
                                    'paid_at' => Carbon::createFromFormat('d/m/Y', ($purchase['date'])),
                                    'proof_of_payment' => 'vendor_payments/' . $file
                                ]);

                                $vendorPayments[$purchase['date'] . $key] = $vendorPayment;
                            }
                        }
                        $laboratoryPurchase->vendorPayments()->attach($vendorPayments[$purchase['date'] . $purchase['brand']]);
                    }
                    break;
                case '10/09/2024':
                    $stripe = [
                        'olab' => "OLAB 1,129.16 - 9 oct 24.png",
                        'swisslab' => "SWISS 22,528.25 - 9 oct 24.png"
                    ];
                    $odessa = "ODESSA 8,281.60 - 9 oct 24.pdf";

                    if ($purchase['method'] == "odessa") {
                        if (!array_key_exists($purchase['date'] . 'odessa', $vendorPayments)) {
                            $vendorPayment =  VendorPayment::create([
                                'paid_at' => Carbon::createFromFormat('d/m/Y', ("09/10/2024")),
                                'proof_of_payment' => 'vendor_payments/' . $odessa
                            ]);

                            $vendorPayments[$purchase['date'] . 'odessa'] = $vendorPayment;
                        }

                        $laboratoryPurchase->vendorPayments()->attach($vendorPayments[$purchase['date'] . 'odessa']);
                    } else {
                        foreach ($stripe as $key => $file) {
                            if (!array_key_exists($purchase['date'] . $key, $vendorPayments)) {
                                $vendorPayment =  VendorPayment::create([
                                    'paid_at' => Carbon::createFromFormat('d/m/Y', ("09/10/2024")),
                                    'proof_of_payment' => 'vendor_payments/' . $file
                                ]);

                                $vendorPayments[$purchase['date'] . $key] = $vendorPayment;
                            }
                        }
                        $laboratoryPurchase->vendorPayments()->attach($vendorPayments[$purchase['date'] . $purchase['brand']]);
                    }
                    break;
                case '14/11/2024':
                    $stripe = "SWISS 12,554.00 - 14 nov 24.png";
                    $odessa = "ODESSA 7,014.58 - 14 nov 24.pdf";

                    if ($purchase['method'] == "odessa") {
                        if (!array_key_exists($purchase['date'] . 'odessa', $vendorPayments)) {
                            $vendorPayment =  VendorPayment::create([
                                'paid_at' => Carbon::createFromFormat('d/m/Y', ($purchase['date'])),
                                'proof_of_payment' => 'vendor_payments/' . $odessa
                            ]);

                            $vendorPayments[$purchase['date'] . 'odessa'] = $vendorPayment;
                        }

                        $laboratoryPurchase->vendorPayments()->attach($vendorPayments[$purchase['date'] . 'odessa']);
                    } else {
                        if (!array_key_exists($purchase['date'] . 'stripe', $vendorPayments)) {
                            $vendorPayment =  VendorPayment::create([
                                'paid_at' => Carbon::createFromFormat('d/m/Y', ($purchase['date'])),
                                'proof_of_payment' => 'vendor_payments/' . $stripe
                            ]);

                            $vendorPayments[$purchase['date'] . 'stripe'] = $vendorPayment;
                        }

                        $laboratoryPurchase->vendorPayments()->attach($vendorPayments[$purchase['date'] . 'stripe']);
                    }
                    break;
                case '11/02/2025':
                    $stripe = [
                        'olab' => "OLAB 11,332.44 - 11 feb 25.png",
                        "azteca" => "AZTECA 376.34 - 11 feb 25.png",
                        'swisslab' => "SWISS 67,935.19 - 11 feb 25.png"
                    ];

                    $odessa = "ODESSA 16,114.47 - 11 feb 25.pdf";

                    if ($purchase['method'] == "odessa") {
                        if (!array_key_exists($purchase['date'] . 'odessa', $vendorPayments)) {
                            $vendorPayment =  VendorPayment::create([
                                'paid_at' => Carbon::createFromFormat('d/m/Y', ($purchase['date'])),
                                'proof_of_payment' => 'vendor_payments/' . $odessa
                            ]);

                            $vendorPayments[$purchase['date'] . 'odessa'] = $vendorPayment;
                        }

                        $laboratoryPurchase->vendorPayments()->attach($vendorPayments[$purchase['date'] . 'odessa']);
                    } else {
                        foreach ($stripe as $key => $file) {
                            if (!array_key_exists($purchase['date'] . $key, $vendorPayments)) {
                                $vendorPayment =  VendorPayment::create([
                                    'paid_at' => Carbon::createFromFormat('d/m/Y', ($purchase['date'])),
                                    'proof_of_payment' => 'vendor_payments/' . $file
                                ]);

                                $vendorPayments[$purchase['date'] . $key] = $vendorPayment;
                            }
                        }
                        $laboratoryPurchase->vendorPayments()->attach($vendorPayments[$purchase['date'] . $purchase['brand']]);
                    }
                    break;

                default:
                    //error
                    dd('error');
                    break;
            }
        }
    }

    protected function migrateMedicalSubscriptions() {}

    protected function verifyMinimalData()
    {
        Log::info('Verifying minimal user/customer data...');

        // Pull all old users from MySQL
        $oldUsers = DB::connection('mysqlold')
            ->table('users')
            ->orderBy('id')
            ->get();

        Log::info("Found {$oldUsers->count()} users in old MySQL DB.");

        $mismatchCount = 0;

        foreach ($oldUsers as $oldUser) {
            $userId = $oldUser->id;

            // 1) Find matching user in new DB
            $newUser = DB::connection('mysql')
                ->table('users')
                ->where('id', $userId)
                ->first();

            Log::info("=== Checking User #{$userId} (Old email: {$oldUser->email}) ===");

            if (!$newUser) {
                Log::error("No new user found for old user ID: $userId");
                $mismatchCount++;
                continue;
            }

            // Compare email
            if ($oldUser->email === $newUser->email) {
                Log::info("  ✓ Email matches: {$oldUser->email}");
            } else {
                Log::warning("  ✗ Email mismatch: old={$oldUser->email}, new={$newUser->email}");
                $mismatchCount++;
            }

            // 2) Get the old "customer" row (if any)
            $oldCustomer = DB::connection('mysqlold')
                ->table('customers')
                ->where('user_id', $userId)
                ->first();

            if (!$oldCustomer) {
                Log::info("  (No old customer found for user $userId; skipping customer checks.)");
                continue;
            }

            Log::info("  Old Customer #{$oldCustomer->id} found (type: {$oldCustomer->customerable_type}).");

            // 2a) Get the new "customer" row
            $newCustomer = DB::connection('mysql')
                ->table('customers')
                ->where('user_id', $userId)
                ->first();

            if (!$newCustomer) {
                Log::error("  ✗ No new customer found for user $userId, old DB had one (ID {$oldCustomer->id}).");
                $mismatchCount++;
                continue;
            }

            Log::info("  New Customer #{$newCustomer->id} found (type: {$newCustomer->customerable_type}).");

            // Compare medical attention ID
            if ($oldCustomer->medical_attention_id === $newCustomer->medical_attention_identifier) {
                Log::info("    ✓ Medical attention ID matches ({$newCustomer->medical_attention_identifier}).");
            } else {
                Log::warning(
                    "    ✗ Medical attention ID mismatch: " .
                        "old={$oldCustomer->medical_attention_id}, new={$newCustomer->medical_attention_identifier}"
                );
                $mismatchCount++;
            }

            // Compare stripe_id
            if ($oldCustomer->stripe_id === $newCustomer->stripe_id) {
                Log::info("    ✓ Stripe ID matches ({$newCustomer->stripe_id}).");
            } else {
                Log::warning("    ✗ Stripe ID mismatch: old={$oldCustomer->stripe_id}, new={$newCustomer->stripe_id}");
                $mismatchCount++;
            }

            // 3) If old is an OdessaAffiliate, compare affiliate fields
            if ($oldCustomer->customerable_type === 'App\Models\OdessaAfiliateMember') {
                Log::info("    Checking Odessa affiliate data...");

                // old "odessa_afiliate_members"
                $oldAffiliate = DB::connection('mysqlold')
                    ->table('odessa_afiliate_members')
                    ->where('id', $oldCustomer->customerable_id)
                    ->first();

                if (!$oldAffiliate) {
                    Log::warning("    ✗ Old Odessa affiliate row missing (ID={$oldCustomer->customerable_id}).");
                    $mismatchCount++;
                } else {
                    // new "odessa_afiliate_accounts"
                    $newAffiliate = DB::connection('mysql')
                        ->table('odessa_afiliate_accounts')
                        ->where('id', $newCustomer->customerable_id)
                        ->first();

                    if (!$newAffiliate) {
                        Log::error("    ✗ No new Odessa affiliate for user $userId, old affiliate ID={$oldAffiliate->id}.");
                        $mismatchCount++;
                    } else {
                        // Compare fields
                        if ($oldAffiliate->odessa_id === $newAffiliate->odessa_identifier) {
                            Log::info("      ✓ Odessa identifier matches ({$newAffiliate->odessa_identifier}).");
                        } else {
                            Log::warning(
                                "      ✗ Odessa ID mismatch: " .
                                    "old={$oldAffiliate->odessa_id}, new={$newAffiliate->odessa_identifier}"
                            );
                            $mismatchCount++;
                        }

                        if ($oldAffiliate->partner_id === $newAffiliate->partner_identifier) {
                            Log::info("      ✓ Partner ID matches ({$newAffiliate->partner_identifier}).");
                        } else {
                            Log::warning(
                                "      ✗ Partner ID mismatch: " .
                                    "old={$oldAffiliate->partner_id}, new={$newAffiliate->partner_identifier}"
                            );
                            $mismatchCount++;
                        }
                    }
                }
            }
            // 4) If old is a FamilyMember, check new family account
            elseif ($oldCustomer->customerable_type === 'App\Models\FamilyMember') {
                Log::info("    Checking FamilyMember data...");

                $oldFamily = DB::connection('mysqlold')
                    ->table('family_members')
                    ->where('id', $oldCustomer->customerable_id)
                    ->first();

                if (!$oldFamily) {
                    Log::warning("    ✗ Old FamilyMember row missing (ID={$oldCustomer->customerable_id}).");
                    $mismatchCount++;
                } else {
                    // Get the parent's OLD customer ID from family_members.customer_id
                    $oldParentCustomerId = $oldFamily->customer_id;

                    // Get the parent's NEW customer ID (same as old ID since we preserved customer IDs)
                    $newParentCustomerId = $oldParentCustomerId;

                    // Get the family account using the child's customerable_id
                    $newFamily = DB::connection('mysql')
                        ->table('family_accounts')
                        ->where('id', $newCustomer->customerable_id)
                        ->first();

                    if (!$newFamily) {
                        Log::error(
                            "    ✗ No new FamilyAccount found for user $userId; old FamilyMember ID={$oldFamily->id}."
                        );
                        $mismatchCount++;
                    } else {
                        if ($newFamily->customer_id == $newParentCustomerId) {
                            Log::info("      ✓ FamilyAccount.customer_id = {$newFamily->customer_id} matches parent's new customer ID");
                        } else {
                            Log::warning(
                                "      ✗ FamilyAccount.customer_id mismatch! " .
                                    "Expected {$newParentCustomerId} (parent), got {$newFamily->customer_id}"
                            );
                            $mismatchCount++;
                        }

                        if ($newCustomer->customerable_id == $newFamily->id) {
                            Log::info("      ✓ Customer.customerable_id matches family_accounts.id ({$newFamily->id})");
                        } else {
                            Log::warning(
                                "      ✗ Customer.customerable_id mismatch! " .
                                    "Expected {$newFamily->id}, got {$newCustomer->customerable_id}"
                            );
                            $mismatchCount++;
                        }
                    }
                }
            }
        }

        Log::info("Finished minimal verification. Mismatches found: $mismatchCount");

        $this->line('Migration completed successfully!');
    }
}
