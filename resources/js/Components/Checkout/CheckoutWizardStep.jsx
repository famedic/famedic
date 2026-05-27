import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import Card from "@/Components/Card";

export default function CheckoutWizardStep({
    title,
    description,
    children,
    error = null,
}) {
    return (
        <Card className="bg-white p-5 sm:p-6 dark:bg-slate-900">
            <Subheading className="text-lg dark:!text-famedic-lime">
                {title}
            </Subheading>
            {description && (
                <Text className="mt-2 text-sm text-zinc-600 dark:text-slate-400">
                    {description}
                </Text>
            )}
            {error && (
                <Text className="mt-2 text-sm text-red-600 dark:text-red-400">
                    {error}
                </Text>
            )}
            <div className="mt-5">{children}</div>
        </Card>
    );
}
