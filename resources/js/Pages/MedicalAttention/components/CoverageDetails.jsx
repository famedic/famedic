import { Subheading } from "@/Components/Catalyst/heading";
import { Strong, Text } from "@/Components/Catalyst/text";
import {
    Tab,
    TabGroup,
    TabList,
    TabPanel,
    TabPanels,
} from "@/Components/Catalyst/tabs";
import { CheckIcon, StarIcon, XMarkIcon } from "@heroicons/react/24/solid";
import { usePage } from "@inertiajs/react";
import clsx from "clsx";

function OdessaStars({ count = 3, className = "text-yellow-400" }) {
    return (
        <span className="flex">
            {Array.from({ length: count }).map((_, i) => (
                <StarIcon key={i} className={clsx("size-5", className)} />
            ))}
        </span>
    );
}

function CheckListItem({ children }) {
    return (
        <li className="flex items-center gap-x-2">
            <CheckIcon className="size-4 min-w-4 stroke-green-200" />
            <Text>{children}</Text>
        </li>
    );
}

function ExcludedListItem({ children }) {
    return (
        <li className="flex items-start gap-x-2">
            <XMarkIcon className="mt-0.5 size-4 min-w-4 text-zinc-400" />
            <Text className="text-zinc-600 dark:text-zinc-400">{children}</Text>
        </li>
    );
}

function PremiumBenefit({ title, items }) {
    return (
        <li className="rounded-lg border border-yellow-200 bg-yellow-50 p-3 dark:border-yellow-800 dark:bg-yellow-900/20">
            <Strong>
                <span className="flex flex-wrap items-center gap-2 text-yellow-600 dark:text-yellow-400">
                    <StarIcon className="size-5 shrink-0 text-yellow-400" />
                    {title}
                    <StarIcon className="size-5 shrink-0 text-yellow-400" />
                </span>
            </Strong>
            <ul className="mt-1 list-inside list-disc marker:!text-yellow-600">
                {items.map((item) => (
                    <li key={item}>
                        <Text>{item}</Text>
                    </li>
                ))}
            </ul>
        </li>
    );
}

function IncludedBenefitsList({ hasOdessaAfiliateAccount }) {
    return (
        <ol className="list-inside list-decimal space-y-3 marker:text-famedic-light">
            <li>
                <Strong>
                    <span className="text-famedic-light">
                        Asistencia telemedicina ilimitadas 24/7
                    </span>
                </Strong>
                <ul className="list-inside list-disc marker:!text-famedic-dark">
                    <li>
                        <Text>
                            Conecta al paciente con médicos generales a través
                            de Videoconferencia y Chat 24/7
                        </Text>
                    </li>
                </ul>
            </li>

            {hasOdessaAfiliateAccount && (
                <>
                    <PremiumBenefit
                        title="Médico en casa hasta 3 veces al año"
                        items={["Consultas médicas a domicilio"]}
                    />
                    <PremiumBenefit
                        title="Ambulancia en emergencia hasta 1 evento al año"
                        items={["Ambulancia terrestre"]}
                    />
                </>
            )}

            <li>
                <Strong>
                    <span className="text-famedic-light">
                        Asistencias telefónicas ilimitadas
                    </span>
                </Strong>
                <ul className="list-inside list-disc marker:!text-famedic-dark">
                    <li>
                        <Text>Psicológica</Text>
                    </li>
                    <li>
                        <Text>Nutricional</Text>
                    </li>
                    <li>
                        <Text>Legal</Text>
                    </li>
                </ul>
            </li>

            {hasOdessaAfiliateAccount && (
                <PremiumBenefit
                    title="Reembolso de 3 medicamentos por familia por año"
                    items={[
                        "Hasta $350 pesos en cada evento",
                        "Reembolso derivado de la consulta con el médico general (telemedicina)",
                    ]}
                />
            )}
        </ol>
    );
}

function CoverageAudienceList({ hasOdessaAfiliateAccount }) {
    return (
        <ul className="space-y-1">
            <CheckListItem>Titular</CheckListItem>
            <CheckListItem>Cónyuge</CheckListItem>
            <CheckListItem>Hijos</CheckListItem>
            {hasOdessaAfiliateAccount && (
                <li className="mt-2 flex items-center gap-2 text-sm text-yellow-600 dark:text-yellow-400">
                    <StarIcon className="size-4 text-yellow-400" />
                    <Text>Plan premium Odessa</Text>
                </li>
            )}
        </ul>
    );
}

function ExcludedBenefitsList({ hasOdessaAfiliateAccount }) {
    if (hasOdessaAfiliateAccount) {
        return (
            <Text className="text-sm text-zinc-600 dark:text-zinc-400">
                Con tu plan Odessa, médico en casa, ambulancia y reembolso de
                medicamentos están incluidos. Revisa la pestaña «Qué incluye».
            </Text>
        );
    }

    return (
        <ul className="space-y-2">
            <ExcludedListItem>Médico en casa</ExcludedListItem>
            <ExcludedListItem>Ambulancia en emergencia</ExcludedListItem>
            <ExcludedListItem>
                Reembolso de medicamentos (disponible en plan Odessa)
            </ExcludedListItem>
        </ul>
    );
}

function tabButtonClass(selected) {
    return clsx(
        "w-full rounded-md px-2 py-2 text-xs font-medium sm:text-sm",
        selected
            ? "bg-famedic-dark text-white dark:bg-zinc-100 dark:text-zinc-900"
            : "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800",
    );
}

function CoverageTabs({ hasOdessaAfiliateAccount }) {
    return (
        <div className="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <TabGroup>
                <TabList className="gap-1 border-b border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-slate-800/50">
                    <Tab className="flex-1">
                        {(selected) => (
                            <div className={tabButtonClass(selected)}>
                                A quién cubre
                            </div>
                        )}
                    </Tab>
                    <Tab className="flex-1">
                        {(selected) => (
                            <div className={tabButtonClass(selected)}>
                                Qué incluye
                            </div>
                        )}
                    </Tab>
                    <Tab className="flex-1">
                        {(selected) => (
                            <div className={tabButtonClass(selected)}>
                                No incluye
                            </div>
                        )}
                    </Tab>
                </TabList>
                <TabPanels className="p-4">
                    <TabPanel>
                        <CoverageAudienceList
                            hasOdessaAfiliateAccount={hasOdessaAfiliateAccount}
                        />
                    </TabPanel>
                    <TabPanel>
                        <IncludedBenefitsList
                            hasOdessaAfiliateAccount={hasOdessaAfiliateAccount}
                        />
                    </TabPanel>
                    <TabPanel>
                        <ExcludedBenefitsList
                            hasOdessaAfiliateAccount={hasOdessaAfiliateAccount}
                        />
                    </TabPanel>
                </TabPanels>
            </TabGroup>
        </div>
    );
}

function CoverageStacked({ hasOdessaAfiliateAccount }) {
    return (
        <div className="space-y-6">
            <div>
                <Subheading className="flex items-center gap-2">
                    A QUIEN CUBRE
                    {hasOdessaAfiliateAccount && (
                        <StarIcon className="size-5 text-yellow-400" />
                    )}
                </Subheading>
                <CoverageAudienceList
                    hasOdessaAfiliateAccount={hasOdessaAfiliateAccount}
                />
            </div>

            <div className="space-y-2">
                <Subheading className="flex items-center gap-2">
                    QUE INCLUYE
                    {hasOdessaAfiliateAccount ? (
                        <OdessaStars />
                    ) : (
                        <span className="flex">
                            <StarIcon className="size-5 text-blue-400" />
                            <StarIcon className="size-5 text-gray-300" />
                            <StarIcon className="size-5 text-gray-300" />
                        </span>
                    )}
                </Subheading>
                <IncludedBenefitsList
                    hasOdessaAfiliateAccount={hasOdessaAfiliateAccount}
                />
            </div>

            {!hasOdessaAfiliateAccount && (
                <div className="space-y-2">
                    <Subheading>NO INCLUYE</Subheading>
                    <ExcludedBenefitsList
                        hasOdessaAfiliateAccount={hasOdessaAfiliateAccount}
                    />
                </div>
            )}
        </div>
    );
}

export default function CoverageDetails({
    hasOdessaAfiliateAccount: hasOdessaProp,
    variant = "stacked",
}) {
    const pageHasOdessa = usePage().props.hasOdessaAfiliateAccount;
    const hasOdessaAfiliateAccount = hasOdessaProp ?? pageHasOdessa;

    if (variant === "tabs") {
        return (
            <CoverageTabs hasOdessaAfiliateAccount={hasOdessaAfiliateAccount} />
        );
    }

    return (
        <CoverageStacked hasOdessaAfiliateAccount={hasOdessaAfiliateAccount} />
    );
}
