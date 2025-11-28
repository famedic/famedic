import SettingsLayout from "@/Layouts/SettingsLayout";
import { useState, useEffect } from "react";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Subheading, Heading } from "@/Components/Catalyst/heading";
import { 
  CheckCircleIcon, 
  DocumentTextIcon,
  ClockIcon,
  ArrowDownTrayIcon,
  EyeIcon,
  DocumentChartBarIcon
} from "@heroicons/react/24/solid";

// Componente de carga
function LoadingState() {
  return (
    <div className="text-center py-12">
      <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-famedic-dark dark:border-famedic-lime mx-auto"></div>
      <Text className="mt-4 text-lg text-zinc-600 dark:text-slate-400">
        Cargando notificaciones...
      </Text>
    </div>
  );
}

// Componente de error
function ErrorState({ error }) {
  return (
    <div className="text-center py-12">
      <div className="mx-auto size-16 text-red-500">⚠️</div>
      <Text className="mt-4 text-lg text-red-600 dark:text-red-400">
        Error al cargar las notificaciones
      </Text>
      <Text className="text-sm text-red-500 dark:text-red-300 mt-2">
        {error}
      </Text>
    </div>
  );
}

export default function LaboratoryResultsList({ notifications = [], stats = {}, hasNotifications = false }) {
  const [downloadingId, setDownloadingId] = useState(null);
  const [activeFilter, setActiveFilter] = useState('all');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    try {
      console.log('🔍 Props recibidos:', { notifications, stats, hasNotifications });
      
      // Validar que notifications sea un array
      if (!Array.isArray(notifications)) {
        throw new Error('Notifications no es un array');
      }
      
      setLoading(false);
    } catch (err) {
      console.error('❌ Error en componente:', err);
      setError(err.message);
      setLoading(false);
    }
  }, [notifications, stats, hasNotifications]);

  // Función segura para formatear fechas
  const formatearFechaMX = (fechaIso) => {
    try {
      if (!fechaIso) return 'Fecha no disponible';
      const date = new Date(fechaIso);
      return date.toLocaleDateString("es-MX", {
        day: "numeric",
        month: "long",
        year: "numeric",
      });
    } catch {
      return 'Fecha inválida';
    }
  };

  const formatearFechaHoraMX = (fechaIso) => {
    try {
      if (!fechaIso) return 'Fecha no disponible';
      const date = new Date(fechaIso);
      return date.toLocaleDateString("es-MX", {
        day: "numeric",
        month: "long",
        year: "numeric",
        hour: '2-digit',
        minute: '2-digit'
      });
    } catch {
      return 'Fecha inválida';
    }
  };

  // Filtrar notificaciones de forma segura
  const filteredNotifications = Array.isArray(notifications) ? notifications.filter(notification => {
    if (!notification || typeof notification !== 'object') return false;
    
    switch (activeFilter) {
      case 'with_pdf':
        return notification.has_pdf === true;
      case 'results':
        return notification.type === 'results';
      case 'unread':
        return !notification.read_at;
      default:
        return true;
    }
  }) : [];

  const getNotificationTypeLabel = (type) => {
    const types = {
      'notification': 'Notificación',
      'results': 'Resultados',
      'status_update': 'Actualización'
    };
    return types[type] || type || 'Desconocido';
  };

  const getStatusConfig = (notification) => {
    if (!notification) {
      return {
        color: 'bg-gray-100 text-gray-800 border-gray-200',
        icon: ClockIcon,
        text: 'Desconocido'
      };
    }

    if (notification.has_pdf) {
      return {
        color: 'bg-green-100 text-green-800 border-green-200 dark:bg-green-900 dark:text-green-200',
        icon: CheckCircleIcon,
        text: '¡Resultados listos!'
      };
    }
    
    if (notification.type === 'results') {
      return {
        color: 'bg-yellow-100 text-yellow-800 border-yellow-200 dark:bg-yellow-900 dark:text-yellow-200',
        icon: ClockIcon,
        text: 'Procesando resultados'
      };
    }

    return {
      color: 'bg-blue-100 text-blue-800 border-blue-200 dark:bg-blue-900 dark:text-blue-200',
      icon: DocumentTextIcon,
      text: getNotificationTypeLabel(notification.type)
    };
  };

  const handleDownloadResult = async (notification) => {
    if (!notification?.pdf_base64) return;

    setDownloadingId(notification.notification_id);

    try {
      const binaryString = atob(notification.pdf_base64);
      const bytes = new Uint8Array(binaryString.length);
      for (let i = 0; i < binaryString.length; i++) {
        bytes[i] = binaryString.charCodeAt(i);
      }
      const blob = new Blob([bytes], { type: "application/pdf" });
      const url = URL.createObjectURL(blob);

      const a = document.createElement("a");
      a.href = url;
      a.download = `Resultados_${notification.laboratory_brand || 'lab'}_${notification.gda_acuse || notification.notification_id}.pdf`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);

      // Intentar marcar como leída (manejar error silenciosamente)
      try {
        await fetch(`/laboratory-results/notification/${notification.notification_id}/mark-read`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
          }
        });
      } catch (fetchError) {
        console.warn('No se pudo marcar como leída:', fetchError);
      }
    } catch (err) {
      alert("Error al descargar los resultados");
      console.error(err);
    } finally {
      setDownloadingId(null);
    }
  };

  const handleViewResult = (notification) => {
    if (!notification?.pdf_base64) return;

    try {
      const binaryString = atob(notification.pdf_base64);
      const bytes = new Uint8Array(binaryString.length);
      for (let i = 0; i < binaryString.length; i++) {
        bytes[i] = binaryString.charCodeAt(i);
      }
      const blob = new Blob([bytes], { type: "application/pdf" });
      const url = URL.createObjectURL(blob);
      window.open(url, '_blank');
    } catch (err) {
      alert("Error al visualizar el PDF");
      console.error(err);
    }
  };

  // Estados de carga y error
  if (loading) {
    return (
      <SettingsLayout title="Mis Notificaciones de Laboratorio">
        <LoadingState />
      </SettingsLayout>
    );
  }

  if (error) {
    return (
      <SettingsLayout title="Mis Notificaciones de Laboratorio">
        <ErrorState error={error} />
      </SettingsLayout>
    );
  }

  return (
    <SettingsLayout title="Mis Notificaciones de Laboratorio">
      <div className="mx-auto max-w-6xl px-3 sm:px-4 py-6 sm:py-8 lg:px-8">
        {/* Header */}
        <div className="text-center mb-8">
          <DocumentChartBarIcon className="mx-auto size-12 sm:size-16 fill-famedic-dark dark:fill-famedic-lime" />
          <Heading className="mt-4 text-2xl sm:text-3xl font-bold text-zinc-900 dark:text-white">
            Mis Notificaciones de Laboratorio
          </Heading>
          <Text className="mt-2 text-zinc-600 dark:text-slate-300">
            Todas las notificaciones y resultados de tus estudios
          </Text>
        </div>

        {/* Estadísticas y Filtros - Solo si hay datos */}
        {notifications.length > 0 && (
          <div className="mb-8 p-6 bg-white dark:bg-slate-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
                <div>
                  <Text className="text-2xl font-bold text-famedic-dark dark:text-famedic-lime">
                    {stats?.total_notifications || 0}
                  </Text>
                  <Text className="text-sm text-zinc-600 dark:text-slate-400">Total</Text>
                </div>
                <div>
                  <Text className="text-2xl font-bold text-green-600 dark:text-green-400">
                    {stats?.with_pdf || 0}
                  </Text>
                  <Text className="text-sm text-zinc-600 dark:text-slate-400">Con PDF</Text>
                </div>
                <div>
                  <Text className="text-2xl font-bold text-blue-600 dark:text-blue-400">
                    {stats?.unread || 0}
                  </Text>
                  <Text className="text-sm text-zinc-600 dark:text-slate-400">No leídas</Text>
                </div>
                <div>
                  <Text className="text-2xl font-bold text-purple-600 dark:text-purple-400">
                    {stats?.by_entity?.quote || 0}
                  </Text>
                  <Text className="text-sm text-zinc-600 dark:text-slate-400">Cotizaciones</Text>
                </div>
              </div>

              <div className="flex flex-wrap gap-2">
                <Button
                  onClick={() => setActiveFilter('all')}
                  plain={activeFilter !== 'all'}
                  className={activeFilter === 'all' ? 'bg-famedic-dark text-white' : ''}
                >
                  Todas
                </Button>
                <Button
                  onClick={() => setActiveFilter('with_pdf')}
                  plain={activeFilter !== 'with_pdf'}
                  className={activeFilter === 'with_pdf' ? 'bg-green-600 text-white' : ''}
                >
                  Con PDF
                </Button>
                <Button
                  onClick={() => setActiveFilter('results')}
                  plain={activeFilter !== 'results'}
                  className={activeFilter === 'results' ? 'bg-blue-600 text-white' : ''}
                >
                  Solo Resultados
                </Button>
                <Button
                  onClick={() => setActiveFilter('unread')}
                  plain={activeFilter !== 'unread'}
                  className={activeFilter === 'unread' ? 'bg-yellow-600 text-white' : ''}
                >
                  No Leídas
                </Button>
              </div>
            </div>
          </div>
        )}

        {/* Lista de notificaciones */}
        {filteredNotifications.length === 0 ? (
          <div className="text-center py-12">
            <ClockIcon className="mx-auto size-16 text-gray-400 dark:text-gray-600" />
            <Text className="mt-4 text-lg text-zinc-600 dark:text-slate-400">
              {activeFilter === 'all' ? 'Aún no tienes notificaciones' : 'No hay notificaciones con este filtro'}
            </Text>
            <Text className="text-sm text-zinc-500 dark:text-slate-500">
              Te notificaremos cuando tengas actualizaciones de tus estudios
            </Text>
          </div>
        ) : (
          <div className="grid gap-4 sm:gap-6">
            {filteredNotifications.map((notification, index) => {
              const status = getStatusConfig(notification);
              const StatusIcon = status.icon;

              return (
                <div
                  key={notification.notification_id || index}
                  className={`rounded-lg bg-white dark:bg-slate-800 shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden ${
                    !notification.read_at ? 'ring-2 ring-blue-500' : ''
                  }`}
                >
                  <div className="p-4 sm:p-6">
                    <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                      <div className="flex-1">
                        <div className="flex flex-wrap items-center gap-2 mb-3">
                          <div className={`flex items-center gap-1.5 px-3 py-1 rounded-full border text-xs font-medium ${status.color}`}>
                            <StatusIcon className="size-3" />
                            {status.text}
                          </div>
                          
                          <span className={`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${
                            notification.entity_type === 'quote' 
                              ? 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200'
                              : 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200'
                          }`}>
                            {notification.entity_type === 'quote' ? 'Cotización' : 'Compra'}
                          </span>

                          {!notification.read_at && (
                            <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                              Nuevo
                            </span>
                          )}
                        </div>

                        <div className="space-y-3">
                          {/* Información principal */}
                          <div>
                            <h3 className="font-semibold text-zinc-900 dark:text-white">
                              {notification.patient_name || 'Paciente no especificado'}
                            </h3>
                            <Text className="text-sm text-zinc-600 dark:text-slate-400">
                              Referencia: {notification.gda_acuse || notification.gda_order_id || 'N/A'} • 
                              Laboratorio: {notification.laboratory_brand || 'No especificado'}
                            </Text>
                          </div>

                          {/* Fechas */}
                          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                            <div>
                              <Text className="text-zinc-500 dark:text-slate-500">Recibida</Text>
                              <Text className="font-medium text-zinc-900 dark:text-white">
                                {formatearFechaHoraMX(notification.created_at)}
                              </Text>
                            </div>
                            {notification.results_received_at && (
                              <div>
                                <Text className="text-zinc-500 dark:text-slate-500">Resultados listos</Text>
                                <Text className="font-medium text-green-600 dark:text-green-400">
                                  {formatearFechaHoraMX(notification.results_received_at)}
                                </Text>
                              </div>
                            )}
                          </div>

                          {/* Estudios */}
                          {notification.items && notification.items.length > 0 && (
                            <div>
                              <Text className="text-sm font-medium text-zinc-700 dark:text-slate-300 mb-2">
                                Estudios ({notification.items.length})
                              </Text>
                              <div className="flex flex-wrap gap-1">
                                {notification.items.slice(0, 3).map((item, i) => (
                                  <span 
                                    key={i}
                                    className="inline-block bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300 text-xs px-2 py-1 rounded"
                                  >
                                    {item?.name || 'Estudio'}
                                  </span>
                                ))}
                                {notification.items.length > 3 && (
                                  <span className="inline-block bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-500 text-xs px-2 py-1 rounded">
                                    +{notification.items.length - 3} más
                                  </span>
                                )}
                              </div>
                            </div>
                          )}
                        </div>
                      </div>

                      {/* Acciones */}
                      <div className="flex sm:flex-col gap-2 sm:ml-4">
                        {notification.has_pdf ? (
                          <>
                            <Button
                              onClick={() => handleViewResult(notification)}
                              className="flex items-center gap-2 whitespace-nowrap bg-blue-600 hover:bg-blue-700 text-white"
                            >
                              <EyeIcon className="w-4 h-4" />
                              Ver
                            </Button>
                            <Button
                              onClick={() => handleDownloadResult(notification)}
                              disabled={downloadingId === notification.notification_id}
                              className="flex items-center gap-2 whitespace-nowrap"
                            >
                              {downloadingId === notification.notification_id ? (
                                <>
                                  <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white"></div>
                                  Descargando...
                                </>
                              ) : (
                                <>
                                  <ArrowDownTrayIcon className="w-4 h-4" />
                                  Descargar
                                </>
                              )}
                            </Button>
                          </>
                        ) : (
                          <Button disabled plain className="whitespace-nowrap">
                            Sin PDF
                          </Button>
                        )}
                      </div>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </SettingsLayout>
  );
}