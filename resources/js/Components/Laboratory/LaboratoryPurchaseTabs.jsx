// LaboratoryPurchaseTabs.jsx - Versión con wrap en móvil
import { 
    UserIcon,
    DocumentTextIcon,
    BuildingStorefrontIcon,
    CreditCardIcon,
    MapPinIcon,
    ShoppingBagIcon,
    BeakerIcon,
    ReceiptPercentIcon,
    ChartBarIcon
} from "@heroicons/react/24/outline";

export default function LaboratoryPurchaseTabs({ activeTab, onTabChange, hasResults, hasInvoice }) {
    const tabs = [
        { id: "paciente", label: "Paciente", icon: UserIcon, show: true },
        { id: "orden", label: "Orden", icon: DocumentTextIcon, show: true },
        { id: "sucursales", label: "Sucursales", icon: BuildingStorefrontIcon, show: true },
        { id: "pago", label: "Pago", icon: CreditCardIcon, show: true },
        { id: "direccion", label: "Dirección", icon: MapPinIcon, show: true },
        { id: "detalles", label: "Detalles", icon: ShoppingBagIcon, show: true },
        { id: "resultados", label: "Resultados", icon: BeakerIcon, show: hasResults },
        { id: "facturas", label: "Facturas", icon: ReceiptPercentIcon, show: true },
        { id: "estado", label: "Estado", icon: ChartBarIcon, show: true },
    ];

    const visibleTabs = tabs.filter(tab => tab.show);

    // Si no hay resultados y el tab activo era resultados, cambiar a paciente
    if (!hasResults && activeTab === "resultados") {
        onTabChange("paciente");
    }

    return (
        <div className="flex flex-wrap gap-2">
            {visibleTabs.map((tab) => {
                const Icon = tab.icon;
                const isActive = activeTab === tab.id;
                
                return (
                    <button
                        key={tab.id}
                        onClick={() => onTabChange(tab.id)}
                        className={`
                            flex items-center gap-2 px-3 py-1.5 sm:px-4 sm:py-2 text-xs sm:text-sm font-medium rounded-lg transition-all
                            ${isActive 
                                ? 'bg-famedic-600 text-white shadow-md' 
                                : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700'
                            }
                        `}
                    >
                        <Icon className={`size-3.5 sm:size-4 ${isActive ? 'text-white' : 'text-gray-500 dark:text-gray-400'}`} />
                        <span>{tab.label}</span>
                    </button>
                );
            })}
        </div>
    );
}