import { CreditCardIcon } from "@heroicons/react/24/outline";
import { Text } from "@/Components/Catalyst/text";
import { Code } from "@/Components/Catalyst/text";
import CreditCardBrand from "@/Components/CreditCardBrand";
import Card from "@/Components/Card";

export default function PaymentTabContent({ purchase }) {
    const hasTransactions = purchase.transactions && purchase.transactions.length > 0;
    const transaction = hasTransactions ? purchase.transactions[0] : null;
    
    return (
        <Card className="p-6">
            <div className="flex items-center gap-3 mb-4">
                <CreditCardIcon className="size-6 text-famedic-500" />
                <Text className="text-lg font-semibold">Método de pago</Text>
            </div>
            
            {!hasTransactions && (
                <Text className="text-gray-500">No registrado</Text>
            )}
            
            {transaction?.payment_method === "odessa" && (
                <div className="flex gap-3 items-center">
                    <img src="/images/odessa.png" alt="odessa" className="h-8 w-8" />
                    <div>
                        <Text>ODESSA</Text>
                        <Text className="text-xs text-orange-600">Cobro a caja de ahorro</Text>
                    </div>
                </div>
            )}
            
            {transaction?.payment_method !== "odessa" && transaction?.details?.card_brand && (
                <div className="flex items-center gap-3">
                    <CreditCardBrand brand={transaction.details.card_brand} />
                    <Code>**** {transaction.details.card_last_four}</Code>
                </div>
            )}
        </Card>
    );
}