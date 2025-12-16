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
  DocumentChartBarIcon,
  ExclamationTriangleIcon
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
        Error al cargar los resultados
      </Text>
      <Text className="text-sm text-red-500 dark:text-red-300 mt-2">
        {error}
      </Text>
    </div>
  );
}

export default function LaboratoryResultsList({ notifications = [], stats = {}, hasNotifications = false }) {
  const [downloadingId, setDownloadingId] = useState(null);
  const [viewingId, setViewingId] = useState(null);
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

  // Función para obtener el tipo de entidad y ID
  const getEntityInfo = (notification) => {
    if (!notification) return { type: 'notification', id: null };
    
    // Si es una cotización
    if (notification.entity_type === 'quote' && notification.entity_id) {
      return { type: 'quote', id: notification.entity_id };
    }
    
    // Si es una compra
    if (notification.entity_type === 'purchase' && notification.entity_id) {
      return { type: 'purchase', id: notification.entity_id };
    }
    
    // Fallback: usar notification_id como referencia
    return { type: 'notification', id: notification.notification_id };
  };

  // Función para ver el PDF
  const handleViewResult = async (notification) => {
    if (!notification?.results_received_at) {
      alert("Los resultados aún no están disponibles");
      return;
    }

    const entityInfo = getEntityInfo(notification);
    setViewingId(notification.notification_id);

    try {
      // Hacer petición al endpoint de ver PDF
      const response = await fetch(`/laboratory-results/${entityInfo.type}/${entityInfo.id}/view`, {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        }
      });

      if (!response.ok) {
        const errorText = await response.text();
        throw new Error(errorText || 'Error al obtener el PDF');
      }

      // Obtener el blob del PDF
      const blob = await response.blob();
      
      // Verificar si el blob es válido
      if (blob.size === 0) {
        throw new Error('El archivo PDF está vacío');
      }
      
      const url = URL.createObjectURL(blob);
      
      // Abrir en nueva pestaña
      const newWindow = window.open(url, '_blank');
      if (!newWindow) {
        alert('Por favor permite ventanas emergentes para ver el PDF');
        URL.revokeObjectURL(url);
      }
      
      // Marcar como leída después de ver
      await markAsRead(notification.notification_id);

    } catch (err) {
      console.error('Error al ver el PDF:', err);
      alert(err.message || "Error al visualizar el resultado");
    } finally {
      setViewingId(null);
    }
  };

  // Función para descargar el PDF
  const handleDownloadResult = async (notification) => {
    if (!notification?.results_received_at) {
      alert("Los resultados aún no están disponibles");
      return;
    }

    const entityInfo = getEntityInfo(notification);
    setDownloadingId(notification.notification_id);

    try {
      // Hacer petición al endpoint de descargar PDF
      const response = await fetch(`/laboratory-results/${entityInfo.type}/${entityInfo.id}/download`, {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        }
      });

      if (!response.ok) {
        const errorText = await response.text();
        throw new Error(errorText || 'Error al descargar el PDF');
      }

      // Obtener el blob del PDF
      const blob = await response.blob();
      
      // Verificar si el blob es válido
      if (blob.size === 0) {
        throw new Error('El archivo PDF está vacío');
      }
      
      const url = URL.createObjectURL(blob);
      
      // Crear enlace de descarga
      const a = document.createElement("a");
      a.href = url;
      a.download = `Resultados_${notification.laboratory_brand || 'lab'}_${notification.gda_acuse || notification.notification_id}.pdf`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);

      // Marcar como leído
      await markAsRead(notification.notification_id);

    } catch (err) {
      console.error('Error al descargar el PDF:', err);
      alert(err.message || "Error al descargar los resultados");
    } finally {
      setDownloadingId(null);
    }
  };

  // Función para marcar como leída
  const markAsRead = async (notificationId) => {
    try {
      await fetch(`/laboratory-results/notification/${notificationId}/mark-read`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        }
      });
      
      // Actualizar el estado local de la notificación
      const notificationElement = document.querySelector(`[data-notification-id="${notificationId}"]`);
      if (notificationElement) {
        notificationElement.classList.remove('ring-2', 'ring-blue-500');
      }
    } catch (error) {
      console.warn('No se pudo marcar como leída:', error);
    }
  };

  // Función para forzar actualización de resultados
  const handleRefreshResults = async (notification) => {
    if (!confirm('¿Estás seguro de que quieres forzar la actualización de los resultados?')) {
      return;
    }

    try {
      const response = await fetch(`/laboratory-results/notification/${notification.notification_id}/refresh`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        }
      });

      const result = await response.json();

      if (result.success) {
        alert('Resultados actualizados correctamente. Por favor, intenta ver/descargar nuevamente.');
        // Recargar la página para mostrar los cambios
        window.location.reload();
      } else {
        throw new Error(result.message);
      }
    } catch (err) {
      console.error('Error al actualizar resultados:', err);
      alert('Error al actualizar resultados: ' + err.message);
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
      case 'available':
        return notification.results_received_at !== null;
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
    
    if (notification.results_received_at && !notification.has_pdf) {
      return {
        color: 'bg-yellow-100 text-yellow-800 border-yellow-200 dark:bg-yellow-900 dark:text-yellow-200',
        icon: ExclamationTriangleIcon,
        text: 'Resultados disponibles'
      };
    }
    
    if (notification.type === 'results') {
      return {
        color: 'bg-blue-100 text-blue-800 border-blue-200 dark:bg-blue-900 dark:text-blue-200',
        icon: ClockIcon,
        text: 'Procesando resultados'
      };
    }

    return {
      color: 'bg-gray-100 text-gray-800 border-gray-200 dark:bg-gray-900 dark:text-gray-200',
      icon: DocumentTextIcon,
      text: getNotificationTypeLabel(notification.type)
    };
  };

  // Verificar si una notificación tiene resultados disponibles
  const hasAvailableResults = (notification) => {
    return notification.results_received_at !== null;
  };

  // Estados de carga y error
  if (loading) {
    return (
      <SettingsLayout title="Mis Resultados de Laboratorio">
        <LoadingState />
      </SettingsLayout>
    );
  }

  if (error) {
    return (
      <SettingsLayout title="Mis Resultados de Laboratorio">
        <ErrorState error={error} />
      </SettingsLayout>
    );
  }

  return (
    <SettingsLayout title="Mis Resultados de Laboratorio">
      <div className="mx-auto max-w-6xl px-3 sm:px-4 py-6 sm:py-8 lg:px-8">
        {/* Header */}
        <div className="text-center mb-8">
          <DocumentChartBarIcon className="mx-auto size-12 sm:size-16 fill-famedic-dark dark:fill-famedic-lime" />
          <Heading className="mt-4 text-2xl sm:text-3xl font-bold text-zinc-900 dark:text-white">
            Mis Resultados de Laboratorio
          </Heading>
          <Text className="mt-2 text-zinc-600 dark:text-slate-300">
            Gestiona y descarga todos los resultados de tus estudios de laboratorio
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
                    {stats?.available_results || 0}
                  </Text>
                  <Text className="text-sm text-zinc-600 dark:text-slate-400">Disponibles</Text>
                </div>
              </div>

              <div className="flex flex-wrap gap-2 justify-center">
                <Button
                  onClick={() => setActiveFilter('all')}
                  plain={activeFilter !== 'all'}
                  className={activeFilter === 'all' ? 'bg-famedic-dark text-white' : ''}
                >
                  Todas
                </Button>
                <Button
                  onClick={() => setActiveFilter('available')}
                  plain={activeFilter !== 'available'}
                  className={activeFilter === 'available' ? 'bg-green-600 text-white' : ''}
                >
                  Disponibles
                </Button>
                <Button
                  onClick={() => setActiveFilter('with_pdf')}
                  plain={activeFilter !== 'with_pdf'}
                  className={activeFilter === 'with_pdf' ? 'bg-blue-600 text-white' : ''}
                >
                  Con PDF
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
              const hasResults = hasAvailableResults(notification);

              return (
                <div
                  key={notification.notification_id || index}
                  data-notification-id={notification.notification_id}
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

                          {hasResults && !notification.has_pdf && (
                            <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                              Descargar desde GDA
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

                          {/* Información de estado */}
                          {hasResults && !notification.has_pdf && (
                            <div className="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-3">
                              <Text className="text-sm text-yellow-800 dark:text-yellow-200">
                                <strong>Nota:</strong> Los resultados están disponibles pero necesitan ser descargados del laboratorio. Esto puede tomar unos segundos.
                              </Text>
                            </div>
                          )}
                        </div>
                      </div>

                      {/* Acciones */}
                      <div className="flex sm:flex-col gap-2 sm:ml-4">
                        {hasResults ? (
                          <>
                            <Button
                              onClick={() => handleViewResult(notification)}
                              disabled={viewingId === notification.notification_id}
                              className="flex items-center gap-2 whitespace-nowrap bg-blue-600 hover:bg-blue-700 text-white disabled:bg-blue-400"
                            >
                              {viewingId === notification.notification_id ? (
                                <>
                                  <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white"></div>
                                  Cargando...
                                </>
                              ) : (
                                <>
                                  <EyeIcon className="w-4 h-4" />
                                  Ver
                                </>
                              )}
                            </Button>
                            <Button
                              onClick={() => handleDownloadResult(notification)}
                              disabled={downloadingId === notification.notification_id}
                              className="flex items-center gap-2 whitespace-nowrap bg-green-600 hover:bg-green-700 text-white disabled:bg-green-400"
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
                            {!notification.has_pdf && (
                              <Button
                                onClick={() => handleRefreshResults(notification)}
                                className="flex items-center gap-2 whitespace-nowrap bg-yellow-600 hover:bg-yellow-700 text-white text-xs"
                              >
                                <ArrowPathIcon className="w-3 h-3" />
                                Actualizar
                              </Button>
                            )}
                          </>
                        ) : (
                          <Button disabled plain className="whitespace-nowrap">
                            <ClockIcon className="w-4 h-4 mr-2" />
                            Pendiente
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

        {/* Información adicional */}
        {notifications.length > 0 && (
          <div className="mt-8 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
            <Text className="text-sm text-blue-800 dark:text-blue-200 text-center">
              <strong>Nota:</strong> Los resultados se descargan automáticamente del laboratorio cuando los visualizas o descargas por primera vez.
            </Text>
          </div>
        )}
      </div>
    </SettingsLayout>
  );
}

// Componente de icono para actualizar (si no está importado)
function ArrowPathIcon(props) {
  return (
    <svg fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24" {...props}>
      <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
    </svg>
  );
}