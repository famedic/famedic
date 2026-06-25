import { Subheading, GradientHeading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { CalendarDaysIcon } from "@heroicons/react/24/solid";
import CoverageDetails from "./CoverageDetails";

export default function CheckoutPlanDetails({
    formattedPrice,
    hasOdessaAfiliateAccount,
}) {
    return (
        <div className="space-y-4">
            <div>
                <Badge color="blue" className="mb-3 text-sm px-3 py-1.5">
                    <CalendarDaysIcon className="size-4" />
                    12 meses de cobertura
                </Badge>
                <GradientHeading noDivider className="!text-3xl">
                    Membresía médica anual
                </GradientHeading>
                <Text className="mt-2 text-zinc-600 dark:text-zinc-400">
                    Acceso a asistencia médica para titular, cónyuge e hijos.
                </Text>
            </div>

            <div className="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-slate-800/50">
                <Text className="text-sm text-zinc-600 dark:text-zinc-400">
                    Un pago único de{" "}
                    <Strong className="text-famedic-dark dark:text-white">
                        {formattedPrice}
                    </Strong>{" "}
                    activa tu membresía familiar por un año completo.
                </Text>
            </div>

            <CoverageDetails
                hasOdessaAfiliateAccount={hasOdessaAfiliateAccount}
                variant="tabs"
            />
        </div>
    );
}
