import { useEffect, useRef, useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Subheading, Heading } from "@/Components/Catalyst/heading";
import FocusedLayout from "@/Layouts/FocusedLayout";
import { Divider } from "@/Components/Catalyst/divider";
import { 
    CheckCircleIcon, 
    DocumentArrowDownIcon, 
    MapPinIcon,
    CalendarIcon,
    UserIcon,
    PhoneIcon,
    EnvelopeIcon,
    BuildingStorefrontIcon
} from "@heroicons/react/24/solid";
import { 
    ClockIcon,
    ExclamationTriangleIcon
} from "@heroicons/react/16/solid";

// Función auxiliar nativa para formatear fecha como: 17 de noviembre de 2025
const formatearFechaMX = (fechaIso) => {
  const date = new Date(fechaIso);
  return date.toLocaleDateString("es-MX", {
    day: "numeric",
    month: "long",
    year: "numeric",
  });
};

const formatearFechaHoraMX = (fechaIso) => {
  const date = new Date(fechaIso);
  return date.toLocaleDateString("es-MX", {
    day: "numeric",
    month: "long",
    year: "numeric",
    hour: '2-digit',
    minute: '2-digit'
  });
};

// Función para formatear precios con separadores de miles
const formatearPrecio = (precio) => {
  if (precio === undefined || precio === null || isNaN(precio)) return '0.00';
  
  return new Intl.NumberFormat('es-MX', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(precio);
};

// Función para formatear precio con símbolo de moneda
const formatearPrecioConMoneda = (precio) => {
  if (precio === undefined || precio === null || isNaN(precio)) return '$0.00 MXN';
  
  return new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(precio);
};

export default function LaboratoryQuoteSuccess({ quote, laboratoryBrand }) {
  const iframeRef = useRef(null);
  const objectUrlRef = useRef(null);
  const [activeTab, setActiveTab] = useState('resumen');
  const [pdfLoaded, setPdfLoaded] = useState(false);
  const [pdfError, setPdfError] = useState(false);

  // CORRECCIÓN: Dividir entre 10000 para obtener el precio correcto en pesos
  const total = quote?.total_cents ? quote.total_cents / 10000 : 0;
  const subtotal = quote?.subtotal_cents ? quote.subtotal_cents / 10000 : 0;
  const discount = quote?.discount_cents ? quote.discount_cents / 10000 : 0;

  console.log('💰 Precios calculados CORREGIDOS:', { 
    total_cents: quote?.total_cents,
    total, 
    subtotal, 
    discount 
  });

  // Función para obtener el precio corregido de los items
  const getItemPrice = (item) => {
    const price = item.price || 0;
    const quantity = item.quantity || 1;
    // Si el precio parece estar en centavos (número muy grande), dividir entre 100
    return price > 1000 ? (price * quantity) / 100 : price * quantity;
  };

  // Función para obtener el nombre del laboratorio
  const getLaboratoryName = () => {
    return laboratoryBrand?.name || 'Laboratorio GDA';
  };

  // Función para obtener la ruta del logo - CORREGIDA (manteniendo tu formato original)
  const getLaboratoryLogo = () => {
    if (!laboratoryBrand?.name) return '/images/gda/logo-gda-default.png';
    return `/images/gda/GDA-${laboratoryBrand.name}.png`;
  };

  const loadPdfInIframe = (base64) => {
    if (!base64 || !iframeRef.current) return;

    try {
      setPdfLoaded(false);
      setPdfError(false);

      // Limpiar URL anterior si existe
      if (objectUrlRef.current) {
        URL.revokeObjectURL(objectUrlRef.current);
        objectUrlRef.current = null;
      }

      // Decodificar base64
      const binaryString = atob(base64);
      const bytes = new Uint8Array(binaryString.length);
      for (let i = 0; i < binaryString.length; i++) {
        bytes[i] = binaryString.charCodeAt(i);
      }
      
      // Crear blob y URL
      const blob = new Blob([bytes], { type: "application/pdf" });
      const url = URL.createObjectURL(blob);
      
      objectUrlRef.current = url;
      
      // Configurar el iframe
      iframeRef.current.src = url;
      iframeRef.current.onload = () => {
        setPdfLoaded(true);
        setPdfError(false);
      };
      
      iframeRef.current.onerror = () => {
        setPdfError(true);
        setPdfLoaded(false);
      };

    } catch (error) {
      console.error("Error al cargar PDF:", error);
      setPdfError(true);
      setPdfLoaded(false);
    }
  };

  useEffect(() => {
    if (quote?.pdf_base64 && activeTab === 'pdf') {
      // Pequeño delay para asegurar que el iframe esté en el DOM
      setTimeout(() => {
        loadPdfInIframe(quote.pdf_base64);
      }, 100);
    }
    
    return () => {
      // Limpiar URL cuando el componente se desmonte o cambie de tab
      if (objectUrlRef.current) {
        URL.revokeObjectURL(objectUrlRef.current);
        objectUrlRef.current = null;
      }
    };
  }, [quote?.pdf_base64, activeTab]);

  // Efecto adicional para recargar PDF cuando se cambia al tab
  useEffect(() => {
    if (activeTab === 'pdf' && quote?.pdf_base64) {
      loadPdfInIframe(quote.pdf_base64);
    }
  }, [activeTab]);

  const handleDownloadPDF = () => {
    if (!quote?.pdf_base64) return;

    try {
      const binaryString = atob(quote.pdf_base64);
      const bytes = new Uint8Array(binaryString.length);
      for (let i = 0; i < binaryString.length; i++) {
        bytes[i] = binaryString.charCodeAt(i);
      }
      const blob = new Blob([bytes], { type: "application/pdf" });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `Cotizacion_${getLaboratoryName()}_${quote.gda_acuse || quote.id}.pdf`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    } catch (error) {
      alert("Error al descargar el PDF");
      console.error(error);
    }
  };

  const handleShareWhatsApp = () => {
    const mensaje = `
¡Tu cotización ${getLaboratoryName()} está lista! 

*Referencia:* ${quote.gda_acuse || "N/A"}
*Total:* ${formatearPrecioConMoneda(total)}
*Vence:* ${formatearFechaMX(quote.expires_at)}

Paga en cualquier sucursal ${getLaboratoryName()} con este código o el PDF adjunto.
    `.trim();

    const url = `https://wa.me/?text=${encodeURIComponent(mensaje)}`;
    window.open(url, "_blank");
  };

  const getStatusBadge = () => {
    switch (quote.status) {
      case 'pending_branch_payment':
        return {
          color: 'bg-yellow-100 text-yellow-800 border-yellow-200 dark:bg-yellow-900 dark:text-yellow-200 dark:border-yellow-700',
          icon: ClockIcon,
          text: 'Pendiente de pago'
        };
      case 'expired':
        return {
          color: 'bg-red-100 text-red-800 border-red-200 dark:bg-red-900 dark:text-red-200 dark:border-red-700',
          icon: ExclamationTriangleIcon,
          text: 'Expirada'
        };
      case 'completed':
        return {
          color: 'bg-green-100 text-green-800 border-green-200 dark:bg-green-900 dark:text-green-200 dark:border-green-700',
          icon: CheckCircleIcon,
          text: 'Completada'
        };
      default:
        return {
          color: 'bg-gray-100 text-gray-800 border-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600',
          icon: ClockIcon,
          text: quote.status
        };
    }
  };

  const statusBadge = getStatusBadge();
  const StatusIcon = statusBadge.icon;

  return (
    <FocusedLayout title="Cotización generada" hideHelpBubble={true}>
      <div className="mx-auto max-w-6xl px-3 sm:px-4 py-6 sm:py-8 lg:px-8">
        {/* Header */}
        <div className="text-center mb-6 sm:mb-8">
          <CheckCircleIcon className="mx-auto size-12 sm:size-16 fill-green-600 dark:fill-famedic-lime" />
          <h1 className="mt-3 sm:mt-4 text-xl sm:text-2xl lg:text-3xl font-bold text-zinc-900 dark:text-white">
            ¡Cotización generada con éxito!
          </h1>
          <div className="mt-2 flex flex-col sm:flex-row items-center justify-center gap-2">
            <Text className="text-sm sm:text-lg text-zinc-600 dark:text-slate-300">
              Paga en cualquier sucursal {getLaboratoryName()}
            </Text>
            <span className={`inline-flex items-center gap-1 px-2 sm:px-3 py-1 rounded-full border text-xs sm:text-sm font-medium ${statusBadge.color}`}>
              <StatusIcon className="size-3 sm:size-4" />
              {statusBadge.text}
            </span>
          </div>
        </div>

        {/* Tabs de Navegación */}
        <div className="flex border-b border-gray-200 dark:border-gray-700 mb-6 sm:mb-8 overflow-x-auto">
          <button
            onClick={() => setActiveTab('resumen')}
            className={`flex-shrink-0 px-3 sm:px-4 py-2 font-medium text-xs sm:text-sm border-b-2 transition-colors ${
              activeTab === 'resumen'
                ? 'border-famedic-dark text-famedic-dark dark:border-famedic-lime dark:text-famedic-lime'
                : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'
            }`}
          >
            Resumen
          </button>
          <button
            onClick={() => setActiveTab('detalles')}
            className={`flex-shrink-0 px-3 sm:px-4 py-2 font-medium text-xs sm:text-sm border-b-2 transition-colors ${
              activeTab === 'detalles'
                ? 'border-famedic-dark text-famedic-dark dark:border-famedic-lime dark:text-famedic-lime'
                : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'
            }`}
          >
            Detalles
          </button>
          <button
            onClick={() => setActiveTab('pdf')}
            className={`flex-shrink-0 px-3 sm:px-4 py-2 font-medium text-xs sm:text-sm border-b-2 transition-colors ${
              activeTab === 'pdf'
                ? 'border-famedic-dark text-famedic-dark dark:border-famedic-lime dark:text-famedic-lime'
                : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'
            }`}
          >
            PDF
          </button>
        </div>

        {/* Contenido de Tabs */}
        {activeTab === 'resumen' && (
          <div className="grid gap-4 sm:gap-6 md:grid-cols-2">
            {/* Información Principal */}
            <div className="space-y-4 sm:space-y-6">
              <div className="rounded-lg bg-white p-4 sm:p-6 shadow dark:bg-slate-800">
                <Subheading className="mb-3 sm:mb-4 flex items-center gap-2 text-zinc-900 dark:text-white text-sm sm:text-base">
                  <CheckCircleIcon className="size-4 sm:size-5 text-green-500" />
                  Información de la Cotización
                </Subheading>
                <dl className="space-y-2 sm:space-y-3 text-sm sm:text-base">
                  <div className="flex justify-between items-start gap-2">
                    <dt className="text-zinc-600 dark:text-slate-300 flex-shrink-0">Referencia {getLaboratoryName()}</dt>
                    <dd className="font-mono font-medium text-famedic-dark dark:text-famedic-lime text-right break-all">
                      {quote.gda_acuse || "Pendiente"}
                    </dd>
                  </div>
                  <div className="flex justify-between items-start gap-2">
                    <dt className="text-zinc-600 dark:text-slate-300 flex-shrink-0">Cotización ID</dt>
                    <dd className="font-medium text-zinc-900 dark:text-white text-right">#{quote.id}</dd>
                  </div>
                  <div className="flex justify-between items-start gap-2">
                    <dt className="text-zinc-600 dark:text-slate-300 flex-shrink-0">Creada</dt>
                    <dd className="text-zinc-900 dark:text-white text-right text-xs sm:text-sm">
                      {formatearFechaHoraMX(quote.created_at)}
                    </dd>
                  </div>
                  <div className="flex justify-between items-start gap-2">
                    <dt className="text-zinc-600 dark:text-slate-300 flex-shrink-0">Vence</dt>
                    <dd className="text-xs sm:text-sm text-red-600 dark:text-red-400 text-right">
                      {formatearFechaHoraMX(quote.expires_at)}
                    </dd>
                  </div>
                </dl>
              </div>

              {/* Información del Paciente */}
              {quote.contact && (
                <div className="rounded-lg bg-white p-4 sm:p-6 shadow dark:bg-slate-800">
                  <Subheading className="mb-3 sm:mb-4 flex items-center gap-2 text-zinc-900 dark:text-white text-sm sm:text-base">
                    <UserIcon className="size-4 sm:size-5 text-blue-500" />
                    Información del Paciente
                  </Subheading>
                  <dl className="space-y-2 text-sm sm:text-base">
                    <div className="flex justify-between items-start gap-2">
                      <dt className="text-zinc-600 dark:text-slate-300 flex-shrink-0">Nombre</dt>
                      <dd className="font-medium text-zinc-900 dark:text-white text-right break-words">
                        {quote.contact.name || 'No especificado'}  {quote.contact.paternal_lastname || 'No especificado'}  {quote.contact.maternal_lastname || 'No especificado'}
                      </dd>
                    </div>
                    {quote.contact.phone && (
                      <div className="flex justify-between items-start gap-2">
                        <dt className="text-zinc-600 dark:text-slate-300 flex-shrink-0">Teléfono</dt>
                        <dd className="font-medium flex items-center gap-1 text-zinc-900 dark:text-white text-right">
                          <PhoneIcon className="size-3 sm:size-4" />
                          {quote.contact.phone}
                        </dd>
                      </div>
                    )}
                    {quote.contact.email && (
                      <div className="flex justify-between items-start gap-2">
                        <dt className="text-zinc-600 dark:text-slate-300 flex-shrink-0">Email</dt>
                        <dd className="font-medium flex items-center gap-1 text-zinc-900 dark:text-white text-right break-all">
                          <EnvelopeIcon className="size-3 sm:size-4" />
                          {quote.contact.email}
                        </dd>
                      </div>
                    )}
                  </dl>
                </div>
              )}

              {/* Información de Laboratorio - CORREGIDO (logo con tu formato original) */}
              <div className="rounded-lg bg-white p-4 sm:p-6 shadow dark:bg-slate-800">
                <Subheading className="mb-3 sm:mb-4 flex items-center gap-2 text-zinc-900 dark:text-white text-sm sm:text-base">
                  <BuildingStorefrontIcon className="size-4 sm:size-5 text-purple-500" />
                  Información del Laboratorio
                </Subheading>
                <div className="flex items-center gap-3 sm:gap-4">
                  <img
                    src={getLaboratoryLogo()}
                    alt={`Logo ${getLaboratoryName()}`}
                    className="w-16 h-16 sm:w-24 sm:h-24 object-contain flex-shrink-0"
                    onError={(e) => {
                      e.target.src = '/images/gda/logo-gda-default.png';
                    }}
                  />
                  <div className="min-w-0">
                    <Text className="font-bold text-sm sm:text-lg text-zinc-900 dark:text-white truncate">
                      {getLaboratoryName()}
                    </Text>
                    <Text className="text-xs sm:text-sm text-gray-600 dark:text-slate-300">
                      Presenta esta cotización en cualquier sucursal {getLaboratoryName()}
                    </Text>
                  </div>
                </div>
              </div>
            </div>

            {/* Resumen de Pago y Estudios */}
            <div className="space-y-4 sm:space-y-6">
              <div className="rounded-lg bg-white p-4 sm:p-6 shadow dark:bg-slate-800">
                <Subheading className="mb-3 sm:mb-4 text-zinc-900 dark:text-white text-sm sm:text-base">
                  Resumen de Pago
                </Subheading>
                <dl className="space-y-2 sm:space-y-3 text-sm sm:text-base">
                  <div className="flex justify-between items-start gap-2">
                    <dt className="text-zinc-600 dark:text-slate-300">Subtotal</dt>
                    <dd className="text-zinc-900 dark:text-white">{formatearPrecioConMoneda(subtotal)} MXN</dd>
                  </div>
                  {discount > 0 && (
                    <div className="flex justify-between items-start gap-2 text-green-600 dark:text-green-400">
                      <dt>Descuento</dt>
                      <dd>-{formatearPrecioConMoneda(discount)} MXN</dd>
                    </div>
                  )}
                  <Divider className="dark:border-gray-600 my-2" />
                  <div className="flex justify-between items-start gap-2 text-base sm:text-lg font-bold">
                    <dt className="text-zinc-900 dark:text-white">Total</dt>
                    <dd className="text-famedic-dark dark:text-famedic-lime">
                      {formatearPrecioConMoneda(total)} MXN
                    </dd>
                  </div>
                </dl>
              </div>

              {/* Estudios Solicitados - CORREGIDO */}
              <div className="rounded-lg bg-white p-4 sm:p-6 shadow dark:bg-slate-800">
                <Subheading className="mb-3 sm:mb-4 text-zinc-900 dark:text-white text-sm sm:text-base">
                  Estudios Solicitados
                </Subheading>
                <div className="space-y-2 sm:space-y-3 max-h-60 sm:max-h-80 overflow-y-auto">
                  {quote.items && quote.items.map((item, index) => {
                    const itemTotal = getItemPrice(item);
                    console.log(`📊 Item ${index}:`, { 
                      name: item.name, 
                      price: item.price, 
                      quantity: item.quantity,
                      itemTotal 
                    });
                    
                    return (
                      <div key={index} className="flex justify-between items-start gap-2 py-2 border-b border-gray-100 dark:border-gray-600 last:border-b-0">
                        <div className="flex-1 min-w-0">
                          <Text className="font-medium text-xs sm:text-sm text-zinc-900 dark:text-white break-words">
                            {item.name}
                          </Text>
                          {item.quantity > 1 && (
                            <Text className="text-xs text-gray-500 dark:text-slate-400">
                              Cantidad: {item.quantity}
                            </Text>
                          )}
                        </div>
                        <Text className="font-medium whitespace-nowrap text-xs sm:text-sm ml-2 text-zinc-900 dark:text-white flex-shrink-0">
                          ${formatearPrecio(itemTotal)} MXN
                        </Text>
                      </div>
                    );
                  })}
                </div>
                {(!quote.items || quote.items.length === 0) && (
                  <Text className="text-gray-500 dark:text-slate-400 text-center py-4 text-sm">
                    No hay estudios solicitados
                  </Text>
                )}
              </div>
            </div>
          </div>
        )}

        {activeTab === 'detalles' && (
          <div className="grid gap-4 sm:gap-6 md:grid-cols-2">
            {/* Información de Dirección */}
            {quote.address && (
              <div className="rounded-lg bg-white p-4 sm:p-6 shadow dark:bg-slate-800">
                <Subheading className="mb-3 sm:mb-4 flex items-center gap-2 text-zinc-900 dark:text-white text-sm sm:text-base">
                  <MapPinIcon className="size-4 sm:size-5 text-red-500" />
                  Dirección de Facturación
                </Subheading>
                <div className="space-y-2 text-sm sm:text-base">
                  <Text className="font-medium text-zinc-900 dark:text-white break-words">
                    {quote.address.full_address || 'Dirección no especificada'}
                  </Text>
                  {quote.address.neighborhood && quote.address.city && (
                    <Text className="text-xs sm:text-sm text-gray-600 dark:text-slate-300 break-words">
                      {quote.address.neighborhood}, {quote.address.city}
                    </Text>
                  )}
                  {quote.address.state && quote.address.zip_code && (
                    <Text className="text-xs sm:text-sm text-gray-600 dark:text-slate-300 break-words">
                      {quote.address.state}, {quote.address.zip_code}
                    </Text>
                  )}
                </div>
              </div>
            )}

            {/* Información de Cita */}
            {quote.appointment && (
              <div className="rounded-lg bg-white p-4 sm:p-6 shadow dark:bg-slate-800">
                <Subheading className="mb-3 sm:mb-4 flex items-center gap-2 text-zinc-900 dark:text-white text-sm sm:text-base">
                  <CalendarIcon className="size-4 sm:size-5 text-purple-500" />
                  Información de Cita
                </Subheading>
                <div className="space-y-2 sm:space-y-3 text-sm sm:text-base">
                  {quote.appointment.scheduled_at && (
                    <div>
                      <Text className="font-medium text-zinc-900 dark:text-white">Fecha y Hora</Text>
                      <Text className="text-zinc-900 dark:text-white text-xs sm:text-sm">
                        {formatearFechaHoraMX(quote.appointment.scheduled_at)}
                      </Text>
                    </div>
                  )}
                  {quote.appointment.laboratory_store && (
                    <div>
                      <Text className="font-medium flex items-center gap-1 text-zinc-900 dark:text-white">
                        <BuildingStorefrontIcon className="size-3 sm:size-4" />
                        Sucursal {getLaboratoryName()}
                      </Text>
                      <Text className="text-zinc-900 dark:text-white text-xs sm:text-sm break-words">
                        {quote.appointment.laboratory_store.name || 'Sucursal no especificada'}
                      </Text>
                      {quote.appointment.laboratory_store.address && (
                        <Text className="text-xs text-gray-600 dark:text-slate-300 break-words">
                          {quote.appointment.laboratory_store.address}
                        </Text>
                      )}
                    </div>
                  )}
                </div>
              </div>
            )}

            {/* Sección vacía si no hay dirección ni cita */}
            {!quote.address && !quote.appointment && (
              <div className="rounded-lg bg-white p-4 sm:p-6 shadow dark:bg-slate-800 md:col-span-2">
                <Subheading className="mb-3 sm:mb-4 text-zinc-900 dark:text-white text-sm sm:text-base">
                  Información Adicional
                </Subheading>
                <Text className="text-gray-500 dark:text-slate-400 text-sm">
                  No hay información adicional disponible para esta cotización.
                </Text>
              </div>
            )}

            {/* Instrucciones de Pago - ACTUALIZADO */}
            <div className="rounded-lg bg-white p-4 sm:p-6 shadow dark:bg-slate-800 md:col-span-2">
              <Subheading className="mb-3 sm:mb-4 text-zinc-900 dark:text-white text-sm sm:text-base">
                Instrucciones de Pago
              </Subheading>
              <div className="space-y-3 sm:space-y-4 text-sm sm:text-base">
                <div className="flex items-start gap-3">
                  <div className="flex-shrink-0 w-6 h-6 sm:w-8 sm:h-8 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mt-0.5">
                    <Text className="text-blue-600 dark:text-blue-300 font-bold text-xs sm:text-sm">1</Text>
                  </div>
                  <div className="min-w-0">
                    <Text className="font-medium text-zinc-900 dark:text-white">Acude a cualquier sucursal {getLaboratoryName()}</Text>
                    <Text className="text-xs sm:text-sm text-gray-600 dark:text-slate-300 mt-1 break-words">
                      Presenta tu referencia <strong className="font-mono text-zinc-900 dark:text-white">{quote.gda_acuse || quote.id}</strong> o el PDF de la cotización
                    </Text>
                  </div>
                </div>
                <div className="flex items-start gap-3">
                  <div className="flex-shrink-0 w-6 h-6 sm:w-8 sm:h-8 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mt-0.5">
                    <Text className="text-blue-600 dark:text-blue-300 font-bold text-xs sm:text-sm">2</Text>
                  </div>
                  <div className="min-w-0">
                    <Text className="font-medium text-zinc-900 dark:text-white">Realiza el pago</Text>
                    <Text className="text-xs sm:text-sm text-gray-600 dark:text-slate-300 mt-1">
                      Paga el monto total de <strong className="text-zinc-900 dark:text-white">{formatearPrecioConMoneda(total)} MXN</strong> en efectivo o con tarjeta
                    </Text>
                  </div>
                </div>
                <div className="flex items-start gap-3">
                  <div className="flex-shrink-0 w-6 h-6 sm:w-8 sm:h-8 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mt-0.5">
                    <Text className="text-blue-600 dark:text-blue-300 font-bold text-xs sm:text-sm">3</Text>
                  </div>
                  <div className="min-w-0">
                    <Text className="font-medium text-zinc-900 dark:text-white">Guarda tu comprobante</Text>
                    <Text className="text-xs sm:text-sm text-gray-600 dark:text-slate-300 mt-1">
                      Conserva el ticket de pago para cualquier aclaración o seguimiento
                    </Text>
                  </div>
                </div>
                <div className="flex items-start gap-3">
                  <div className="flex-shrink-0 w-6 h-6 sm:w-8 sm:h-8 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mt-0.5">
                    <Text className="text-blue-600 dark:text-blue-300 font-bold text-xs sm:text-sm">4</Text>
                  </div>
                  <div className="min-w-0">
                    <Text className="font-medium text-zinc-900 dark:text-white">Realiza tus estudios</Text>
                    <Text className="text-xs sm:text-sm text-gray-600 dark:text-slate-300 mt-1">
                      Acude a tu cita programada o agenda una nueva en sucursal {getLaboratoryName()}
                    </Text>
                  </div>
                </div>
              </div>

              {/* Información importante */}
              <div className="mt-4 sm:mt-6 p-3 sm:p-4 bg-yellow-50 dark:bg-yellow-900 border border-yellow-200 dark:border-yellow-700 rounded-lg">
                <Text className="text-xs sm:text-sm text-yellow-800 dark:text-yellow-200 break-words">
                  <strong>Importante:</strong> Esta cotización vence el {formatearFechaMX(quote.expires_at)}. 
                  Después de esta fecha, deberás generar una nueva cotización en {getLaboratoryName()}.
                </Text>
              </div>
            </div>
          </div>
        )}

        {activeTab === 'pdf' && (
          <div className="grid gap-4 sm:gap-6">
            <div className="rounded-lg bg-white p-4 sm:p-6 shadow dark:bg-slate-800">
              <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-4">
                <Subheading className="text-zinc-900 dark:text-white text-sm sm:text-base">
                  Vista Previa del PDF - {getLaboratoryName()}
                </Subheading>
                <div className="flex gap-2 flex-wrap">
                  <Button
                    onClick={() => quote?.pdf_base64 && loadPdfInIframe(quote.pdf_base64)}
                    disabled={!quote?.pdf_base64}
                    plain
                    className="flex items-center gap-2 text-xs"
                  >
                    <svg className="w-3 h-3 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    Recargar
                  </Button>
                  <Button
                    onClick={handleDownloadPDF}
                    disabled={!quote?.pdf_base64}
                    className="flex items-center gap-2 text-xs"
                  >
                    <DocumentArrowDownIcon className="w-3 h-3 sm:w-4 sm:h-4" />
                    Descargar PDF
                  </Button>
                </div>
              </div>
              
              <div className="relative w-full h-[400px] sm:h-[500px] lg:h-[600px] border rounded-lg overflow-hidden bg-gray-50 dark:bg-gray-900">
                {quote?.pdf_base64 ? (
                  <>
                    {!pdfLoaded && !pdfError && (
                      <div className="absolute inset-0 flex flex-col items-center justify-center bg-white dark:bg-gray-900 z-10">
                        <div className="animate-spin rounded-full h-8 w-8 sm:h-12 sm:w-12 border-b-2 border-famedic-dark dark:border-famedic-lime"></div>
                        <Text className="mt-3 text-zinc-600 dark:text-slate-300 text-sm">Cargando PDF de {getLaboratoryName()}...</Text>
                      </div>
                    )}
                    
                    {pdfError && (
                      <div className="absolute inset-0 flex flex-col items-center justify-center bg-white dark:bg-gray-900 z-10">
                        <ExclamationTriangleIcon className="size-8 sm:size-12 text-red-500" />
                        <Text className="mt-3 text-red-600 dark:text-red-400 text-sm text-center px-4">
                          Error al cargar el PDF de {getLaboratoryName()}
                        </Text>
                        <Button
                          onClick={() => loadPdfInIframe(quote.pdf_base64)}
                          className="mt-3 text-xs"
                        >
                          Reintentar
                        </Button>
                      </div>
                    )}

                    <iframe
                      ref={iframeRef}
                      className={`absolute inset-0 w-full h-full ${!pdfLoaded || pdfError ? 'opacity-0' : 'opacity-100'}`}
                      title={`Cotización ${getLaboratoryName()} PDF`}
                      sandbox="allow-scripts allow-same-origin"
                      loading="lazy"
                      onLoad={() => setPdfLoaded(true)}
                      onError={() => setPdfError(true)}
                    />
                  </>
                ) : (
                  <div className="flex flex-col items-center justify-center h-full space-y-2 sm:space-y-3">
                    <DocumentArrowDownIcon className="size-8 sm:size-12 text-gray-400 dark:text-gray-500" />
                    <Text className="text-zinc-500 dark:text-slate-400 text-sm">PDF de {getLaboratoryName()} no disponible</Text>
                    <Text className="text-xs text-zinc-400 dark:text-slate-500 text-center px-4">
                      La cotización no incluye documento PDF
                    </Text>
                  </div>
                )}
              </div>
            </div>
          </div>
        )}

        <Divider className="my-6 sm:my-8 dark:border-gray-700" />

        {/* Botones de Acción */}
        <div className="flex flex-col gap-3 sm:gap-4 sm:flex-row sm:justify-center">
          <Button
            onClick={handleDownloadPDF}
            disabled={!quote?.pdf_base64}
            className="flex items-center justify-center gap-2 !py-2 sm:!py-3 text-xs sm:text-sm"
          >
            <DocumentArrowDownIcon className="w-4 h-4 sm:w-5 sm:h-5" />
            Descargar PDF
          </Button>

          <Button
            onClick={handleShareWhatsApp}
            plain
            className="flex items-center justify-center gap-2 !py-2 sm:!py-3 text-xs sm:text-sm border border-green-600 text-green-600 hover:bg-green-50 dark:border-green-500 dark:text-green-400 dark:hover:bg-slate-800"
          >
            <svg className="w-4 h-4 sm:w-5 sm:h-5" viewBox="0 0 24 24" fill="currentColor">
              <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.297-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.626.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488"/>
            </svg>
            Enviar por WhatsApp
          </Button>
        </div>

        <Text className="mt-6 sm:mt-8 text-center text-xs sm:text-sm text-zinc-500 dark:text-slate-400 px-2">
          Acude a cualquier sucursal {getLaboratoryName()} con tu referencia o el PDF antes del {formatearFechaMX(quote.expires_at)}.
        </Text>
      </div>
    </FocusedLayout>
  );
}