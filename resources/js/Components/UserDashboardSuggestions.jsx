// Components/UserDashboardStats.jsx
import { useEffect, useState } from "react";
import { 
    CreditCardIcon, 
    MapPinIcon, 
    UserGroupIcon,
    ShoppingBagIcon,
    DocumentChartBarIcon,
    ReceiptPercentIcon,
    ChartBarIcon,
    ArrowTrendingUpIcon,
    ArrowTrendingDownIcon
} from "@heroicons/react/24/outline";
import { Link } from "@inertiajs/react";
import clsx from "clsx";

export default function UserDashboardStats({ user, stats, recentResults }) {
    const [statsData, setStatsData] = useState([]);
    const [totalSpent, setTotalSpent] = useState(0);

    useEffect(() => {
        calculateStats();
    }, [stats, recentResults]);

    const calculateStats = () => {
        // Calcular total gastado (ejemplo - ajusta según tu estructura)
        if (stats?.recentPurchasesTotal) {
            setTotalSpent(stats.recentPurchasesTotal);
        }
        
        const data = [
            {
                id: "family",
                title: "Familiares",
                value: stats?.contactsCount || 0,
                icon: UserGroupIcon,
                href: route("contacts.index"),
                status: stats?.hasContacts ? "completed" : "pending",
                color: stats?.hasContacts ? "from-green-500 to-emerald-500" : "from-orange-500 to-red-500",
                bgColor: stats?.hasContacts ? "bg-green-500/20" : "bg-orange-500/20",
                textColor: stats?.hasContacts ? "text-green-600" : "text-orange-600",
                borderColor: stats?.hasContacts ? "border-green-200" : "border-orange-200",
                action: stats?.hasContacts ? "Ver familiares" : "Agregar familiares"
            },
            {
                id: "addresses",
                title: "Direcciones",
                value: stats?.addressesCount || 0,
                icon: MapPinIcon,
                href: route("addresses.index"),
                status: stats?.hasAddresses ? "completed" : "pending",
                color: stats?.hasAddresses ? "from-green-500 to-emerald-500" : "from-orange-500 to-red-500",
                bgColor: stats?.hasAddresses ? "bg-green-500/20" : "bg-orange-500/20",
                textColor: stats?.hasAddresses ? "text-green-600" : "text-orange-600",
                borderColor: stats?.hasAddresses ? "border-green-200" : "border-orange-200",
                action: stats?.hasAddresses ? "Ver direcciones" : "Agregar dirección"
            },
            {
                id: "payment-methods",
                title: "Métodos de pago",
                value: stats?.paymentMethodsCount || 0,
                icon: CreditCardIcon,
                href: route("payment-methods.index"),
                status: stats?.hasPaymentMethods ? "completed" : "pending",
                color: stats?.hasPaymentMethods ? "from-green-500 to-emerald-500" : "from-orange-500 to-red-500",
                bgColor: stats?.hasPaymentMethods ? "bg-green-500/20" : "bg-orange-500/20",
                textColor: stats?.hasPaymentMethods ? "text-green-600" : "text-orange-600",
                borderColor: stats?.hasPaymentMethods ? "border-green-200" : "border-orange-200",
                action: stats?.hasPaymentMethods ? "Ver tarjetas" : "Agregar tarjeta"
            },
            {
                id: "purchases",
                title: "Compras",
                value: stats?.purchasesCount || 0,
                icon: ShoppingBagIcon,
                href: route("laboratory-purchases.index"),
                status: stats?.hasRecentPurchases ? "completed" : "pending",
                color: stats?.hasRecentPurchases ? "from-blue-500 to-cyan-500" : "from-gray-400 to-gray-500",
                bgColor: stats?.hasRecentPurchases ? "bg-blue-500/20" : "bg-gray-500/20",
                textColor: stats?.hasRecentPurchases ? "text-blue-600" : "text-gray-600",
                borderColor: stats?.hasRecentPurchases ? "border-blue-200" : "border-gray-200",
                action: stats?.hasRecentPurchases ? "Ver compras" : "Realizar compra"
            },
            {
                id: "results",
                title: "Resultados nuevos",
                value: recentResults?.length || 0,
                icon: DocumentChartBarIcon,
                href: route("laboratory-results.index"),
                status: recentResults?.length > 0 ? "completed" : "pending",
                color: recentResults?.length > 0 ? "from-purple-500 to-pink-500" : "from-gray-400 to-gray-500",
                bgColor: recentResults?.length > 0 ? "bg-purple-500/20" : "bg-gray-500/20",
                textColor: recentResults?.length > 0 ? "text-purple-600" : "text-gray-600",
                borderColor: recentResults?.length > 0 ? "border-purple-200" : "border-gray-200",
                action: recentResults?.length > 0 ? "Ver resultados" : "Sin resultados recientes"
            },
            {
                id: "invoices",
                title: "Facturas",
                value: stats?.invoicesCount || 0,
                icon: ReceiptPercentIcon,
                href: route("tax-profiles.index"),
                status: stats?.hasInvoices ? "completed" : "pending",
                color: stats?.hasInvoices ? "from-indigo-500 to-purple-500" : "from-gray-400 to-gray-500",
                bgColor: stats?.hasInvoices ? "bg-indigo-500/20" : "bg-gray-500/20",
                textColor: stats?.hasInvoices ? "text-indigo-600" : "text-gray-600",
                borderColor: stats?.hasInvoices ? "border-indigo-200" : "border-gray-200",
                action: stats?.hasInvoices ? "Ver facturas" : "Configurar facturación"
            }
        ];
        
        setStatsData(data);
    };

    return (
        <div className="space-y-4">
            {/* Grid de estadísticas compactas */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                {statsData.map((item) => (
                    <Link
                        key={item.id}
                        href={item.href}
                        className={clsx(
                            "group relative overflow-hidden rounded-xl border p-3 transition-all duration-300 hover:shadow-md",
                            item.borderColor,
                            item.bgColor
                        )}
                    >
                        <div className="relative flex flex-col items-center text-center">
                            <div className={clsx(
                                "rounded-full p-2",
                                item.status === "completed" ? "bg-white/50" : "bg-white/30"
                            )}>
                                <item.icon className={clsx(
                                    "h-5 w-5",
                                    item.textColor
                                )} />
                            </div>
                            <div className="mt-2">
                                <p className={clsx(
                                    "text-2xl font-bold",
                                    item.textColor
                                )}>
                                    {item.value}
                                </p>
                                <p className="text-xs text-gray-600">
                                    {item.title}
                                </p>
                            </div>
                        </div>
                        
                        {/* Indicador de estado */}
                        <div className={clsx(
                            "absolute bottom-0 left-0 h-1 w-full transition-all duration-300",
                            item.status === "completed" ? "bg-green-500" : "bg-orange-500"
                        )} />
                    </Link>
                ))}
            </div>
            
            {/* Gráfica simple de gastos (si hay datos) */}
            {stats?.monthlySpending && stats.monthlySpending.length > 0 && (
                <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div className="flex items-center justify-between mb-3">
                        <div className="flex items-center gap-2">
                            <ChartBarIcon className="h-5 w-5 text-gray-600" />
                            <h4 className="text-sm font-semibold text-gray-700">Gastos mensuales</h4>
                        </div>
                        <span className="text-xs text-gray-500">
                            Total: ${totalSpent.toLocaleString()} MXN
                        </span>
                    </div>
                    
                    {/* Barras de gastos */}
                    <div className="space-y-2">
                        {stats.monthlySpending.slice(0, 6).map((month, idx) => (
                            <div key={idx} className="flex items-center gap-2">
                                <span className="w-12 text-xs text-gray-500">{month.month}</span>
                                <div className="flex-1">
                                    <div className="h-6 overflow-hidden rounded-full bg-gray-100">
                                        <div
                                            className={clsx(
                                                "h-full rounded-full transition-all duration-500",
                                                month.amount > 0 ? "bg-gradient-to-r from-famedic-lime to-green-500" : "bg-gray-300"
                                            )}
                                            style={{ width: `${Math.min((month.amount / (stats.maxSpending || 1)) * 100, 100)}%` }}
                                        />
                                    </div>
                                </div>
                                <span className="w-16 text-right text-xs font-medium text-gray-600">
                                    ${month.amount.toLocaleString()}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            )}
            
            {/* Resultados recientes */}
            {recentResults && recentResults.length > 0 && (
                <div className="rounded-xl border border-purple-200 bg-purple-50/30 p-3">
                    <div className="flex items-center justify-between mb-2">
                        <div className="flex items-center gap-2">
                            <DocumentChartBarIcon className="h-4 w-4 text-purple-600" />
                            <span className="text-xs font-medium text-purple-700">Resultados recientes (últimos 10 días)</span>
                        </div>
                        <Link
                            href={route("laboratory-results.index")}
                            className="text-xs text-purple-600 hover:underline"
                        >
                            Ver todos
                        </Link>
                    </div>
                    <div className="space-y-1">
                        {recentResults.slice(0, 3).map((result, idx) => (
                            <div key={idx} className="flex items-center justify-between text-xs">
                                <span className="text-gray-600">{result.name}</span>
                                <span className="text-gray-400">{result.date}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}
            
            {/* Mensaje de bienvenida compacto */}
            <div className="text-center text-xs text-gray-500">
                {!stats?.profileIsComplete && (
                    <span className="text-orange-600">⚠️ Completa tu perfil para activar todos los servicios</span>
                )}
                {stats?.profileIsComplete && stats?.hasRecentPurchases === false && (
                    <span className="text-blue-600">💡 Realiza tu primera compra y obtén 50% de descuento</span>
                )}
                {stats?.profileIsComplete && stats?.hasRecentPurchases && stats?.hasInvoices === false && (
                    <span className="text-indigo-600">📄 Configura tus datos fiscales para facturar</span>
                )}
            </div>
        </div>
    );
}