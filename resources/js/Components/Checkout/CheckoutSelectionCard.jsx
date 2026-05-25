import { Subheading } from "@/Components/Catalyst/heading";
import clsx from "clsx";
import Card from "@/Components/Card";

export default function CheckoutSelectionCard({
    heading,
    IconComponent,
    children,
    className,
    greenIcon = false,
    selected = false,
    showRadio = false,
    ...props
}) {
    return (
        <Card
            hoverable={!selected}
            className={clsx(
                "flex h-full w-full flex-col p-4",
                !showRadio && "hover:ring-1 md:aspect-[16/9]",
                selected &&
                    "ring-2 ring-famedic-dark dark:ring-famedic-lime bg-famedic-dark/5 dark:bg-famedic-lime/5",
                className,
            )}
            {...props}
        >
            <div className="pointer-events-none h-full w-full">
                <div className="flex h-full items-start gap-3">
                    {showRadio && (
                        <div
                            className={clsx(
                                "mt-1 flex size-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors",
                                selected
                                    ? "border-famedic-dark bg-famedic-dark dark:border-famedic-lime dark:bg-famedic-lime"
                                    : "border-zinc-300 dark:border-slate-600",
                            )}
                        >
                            {selected && (
                                <div className="size-2 rounded-full bg-white dark:bg-famedic-darker" />
                            )}
                        </div>
                    )}
                    {IconComponent && !showRadio && (
                        <IconComponent
                            className={clsx(
                                "mt-1.5 size-4 flex-shrink-0 sm:mt-1",
                                greenIcon
                                    ? "fill-green-500"
                                    : "fill-zinc-300 dark:fill-slate-600",
                            )}
                        />
                    )}
                    <div className="h-full w-full min-w-0">
                        {heading && (
                            <Subheading className="mb-2 line-clamp-1">
                                {heading}
                            </Subheading>
                        )}
                        {children}
                    </div>
                </div>
            </div>
        </Card>
    );
}
