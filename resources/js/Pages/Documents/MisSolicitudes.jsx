import FamedicLayout from "@/Layouts/FamedicLayout";
import { Link } from "@inertiajs/react";

export default function MisSolicitudes({ name, solicitudes }) {
    const formatFecha = (fecha) => {
        return new Date(fecha).toLocaleDateString('es-MX', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });
    };

    const getEstadoBadge = (estado) => {
        const estilos = {
            pendiente: "bg-yellow-100 text-yellow-800",
            en_proceso: "bg-blue-100 text-blue-800",
            atendida: "bg-green-100 text-green-800",
            rechazada: "bg-red-100 text-red-800",
        };
        
        const textos = {
            pendiente: "Pendiente",
            en_proceso: "En Proceso",
            atendida: "Atendida",
            rechazada: "Rechazada",
        };
        
        return (
            <span className={`px-2 py-1 text-xs font-semibold rounded-full ${estilos[estado] || estilos.pendiente}`}>
                {textos[estado] || estado}
            </span>
        );
    };

    return (
        <FamedicLayout title={name}>
            <div className="bg-white py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="lg:text-center mb-8">
                        <h1 className="font-poppins text-3xl font-bold tracking-tight text-famedic-darker sm:text-4xl">
                            {name}
                        </h1>
                        <p className="mt-2 text-lg text-gray-600">
                            Historial de solicitudes de ejercicio de derechos ARCO
                        </p>
                    </div>

                    <div className="mb-6 flex justify-between items-center">
                        <div className="text-sm text-gray-600">
                            Total de solicitudes: <span className="font-semibold">{solicitudes.total}</span>
                        </div>
                        <Link
                            href={route('rights-arco')}
                            className="inline-flex items-center px-4 py-2 bg-famedic-light text-white rounded-md hover:bg-famedic-dark focus:outline-none focus:ring-2 focus:ring-famedic-light"
                        >
                            Nueva Solicitud
                        </Link>
                    </div>

                    {solicitudes.data.length === 0 ? (
                        <div className="text-center py-12 bg-gray-50 rounded-lg">
                            <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <h3 className="mt-2 text-sm font-medium text-gray-900">No hay solicitudes</h3>
                            <p className="mt-1 text-sm text-gray-500">Comienza creando una nueva solicitud de derechos ARCO.</p>
                            <div className="mt-6">
                                <Link
                                    href={route('rights-arco')}
                                    className="inline-flex items-center px-4 py-2 bg-famedic-light text-white rounded-md hover:bg-famedic-dark focus:outline-none focus:ring-2 focus:ring-famedic-light"
                                >
                                    Crear primera solicitud
                                </Link>
                            </div>
                        </div>
                    ) : (
                        <div className="bg-white shadow overflow-hidden sm:rounded-md">
                            <ul className="divide-y divide-gray-200">
                                {solicitudes.data.map((solicitud) => (
                                    <li key={solicitud.id}>
                                        <Link 
                                            href={route('ver-solicitud-arco', solicitud.id)}
                                            className="block hover:bg-gray-50"
                                        >
                                            <div className="px-4 py-4 sm:px-6">
                                                <div className="flex items-center justify-between">
                                                    <div className="flex items-center">
                                                        <div className="ml-3">
                                                            <p className="text-sm font-medium text-famedic-darker">
                                                                {solicitud.folio}
                                                            </p>
                                                            <p className="text-sm text-gray-500">
                                                                {solicitud.nombre_completo}
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <div className="flex flex-col items-end">
                                                        {getEstadoBadge(solicitud.estado)}
                                                        <p className="mt-1 text-xs text-gray-500">
                                                            {formatFecha(solicitud.created_at)}
                                                        </p>
                                                    </div>
                                                </div>
                                                <div className="mt-2">
                                                    <div className="flex flex-wrap gap-2">
                                                        {solicitud.derecho_acceso && (
                                                            <span className="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                                                Acceso
                                                            </span>
                                                        )}
                                                        {solicitud.derecho_rectificacion && (
                                                            <span className="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-green-100 text-green-800">
                                                                Rectificación
                                                            </span>
                                                        )}
                                                        {solicitud.derecho_cancelacion && (
                                                            <span className="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-red-100 text-red-800">
                                                                Cancelación
                                                            </span>
                                                        )}
                                                        {solicitud.derecho_oposicion && (
                                                            <span className="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-yellow-100 text-yellow-800">
                                                                Oposición
                                                            </span>
                                                        )}
                                                        {solicitud.derecho_revocacion && (
                                                            <span className="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                                                Revocación
                                                            </span>
                                                        )}
                                                    </div>
                                                    <p className="mt-2 text-sm text-gray-600 line-clamp-2">
                                                        {solicitud.razon_solicitud}
                                                    </p>
                                                </div>
                                            </div>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                            
                            {/* Paginación */}
                            {solicitudes.links && (
                                <div className="bg-white px-4 py-3 border-t border-gray-200 sm:px-6">
                                    <div className="flex justify-between">
                                        <div className="flex-1 flex justify-between sm:hidden">
                                            <Link
                                                href={solicitudes.prev_page_url}
                                                className="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                                                disabled={!solicitudes.prev_page_url}
                                            >
                                                Anterior
                                            </Link>
                                            <Link
                                                href={solicitudes.next_page_url}
                                                className="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                                                disabled={!solicitudes.next_page_url}
                                            >
                                                Siguiente
                                            </Link>
                                        </div>
                                        <div className="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                                            <div>
                                                <p className="text-sm text-gray-700">
                                                    Mostrando <span className="font-medium">{solicitudes.from}</span> a{' '}
                                                    <span className="font-medium">{solicitudes.to}</span> de{' '}
                                                    <span className="font-medium">{solicitudes.total}</span> resultados
                                                </p>
                                            </div>
                                            <div>
                                                <nav className="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                                    {solicitudes.links.map((link, index) => (
                                                        <Link
                                                            key={index}
                                                            href={link.url || '#'}
                                                            className={`relative inline-flex items-center px-4 py-2 border text-sm font-medium ${
                                                                link.active
                                                                    ? 'z-10 bg-famedic-light border-famedic-light text-white'
                                                                    : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'
                                                            } ${
                                                                !link.url ? 'cursor-not-allowed opacity-50' : ''
                                                            }`}
                                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                                        />
                                                    ))}
                                                </nav>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                    
                    <div className="mt-8 p-6 bg-gray-50 rounded-lg">
                        <h3 className="text-lg font-semibold text-gray-800 mb-4">
                            Información importante
                        </h3>
                        <ul className="list-disc pl-6 space-y-2 text-gray-700">
                            <li>El plazo máximo de respuesta es de 20 días hábiles</li>
                            <li>Puedes descargar los documentos adjuntos en cada solicitud</li>
                            <li>Recibirás notificaciones por correo electrónico sobre el estado de tu solicitud</li>
                            <li>Para cualquier duda, contacta al Departamento de Datos Personales</li>
                        </ul>
                    </div>
                </div>
            </div>
        </FamedicLayout>
    );
}