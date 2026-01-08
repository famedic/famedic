import React, { useState, useEffect, useRef } from 'react';
import { router } from '@inertiajs/react';

const PaymentStatus = ({ transactionId }) => {
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState('pending');
  const [transaction, setTransaction] = useState(null);
  const channelRef = useRef(null);
  const pollIntervalRef = useRef(null);

  // Escuchar notificaciones en tiempo real
  const setupWebSocketListener = () => {
    if (!window.Echo) {
      console.error('Echo no está disponible');
      return;
    }

    // Cancelar canal anterior si existe
    if (channelRef.current) {
      channelRef.current.stopListening('.status-updated');
    }

    // Crear nuevo canal
    const channel = window.Echo.channel(`payment.${transactionId}`);
    
    channel.listen('.status-updated', (data) => {
      console.log('Payment status updated via WebSocket:', data);
      setStatus(data.status);
      
      if (data.status === 'approved' || data.status === 'declined') {
        setLoading(false);
        
        // Si la transacción viene en los datos, actualizarla
        if (data.transaction) {
          setTransaction(data.transaction);
        }
      }
    });

    // Escuchar errores de conexión
    channel.error((error) => {
      console.error('WebSocket channel error:', error);
    });

    channelRef.current = channel;
    return channel;
  };

  // Verificar estado inicial
  const checkStatus = async () => {
    try {
      const response = await fetch(`/api/transactions/${transactionId}/status`);
      const data = await response.json();
      
      if (data.status && data.status !== 'pending') {
        setStatus(data.status);
        setLoading(false);
      }
      
      if (data.transaction) {
        setTransaction(data.transaction);
      }
    } catch (error) {
      console.error('Error checking status:', error);
    }
  };

  const goToOrder = () => {
    if (transaction?.transactionable_id) {
      router.get(route('laboratory-purchases.show', { 
        laboratory_purchase: transaction.transactionable_id 
      }));
    }
  };

  const goToCheckout = () => {
    router.get(route('checkout'));
  };

  useEffect(() => {
    // Verificar que Echo esté disponible
    if (window.Echo) {
      const channel = setupWebSocketListener();
      
      // Configurar reconexión si se pierde la conexión
      window.Echo.connector.pusher.connection.bind('connected', () => {
        console.log('WebSocket reconnected, setting up listener again');
        setupWebSocketListener();
      });
    } else {
      console.warn('WebSockets no disponibles, usando solo polling');
    }

    // Verificar estado inicial
    checkStatus();
    
    // Hacer polling cada 10 segundos como respaldo
    pollIntervalRef.current = setInterval(checkStatus, 10000);

    // Limpiar al desmontar
    return () => {
      if (channelRef.current) {
        channelRef.current.stopListening('.status-updated');
      }
      if (pollIntervalRef.current) {
        clearInterval(pollIntervalRef.current);
      }
    };
  }, [transactionId]);

  // Mostrar loading
  if (loading) {
    return (
      <div className="text-center p-8">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
        <p className="mt-4 text-gray-600">Procesando tu pago...</p>
        <p className="text-sm text-gray-500 mt-2">Por favor no cierres esta ventana.</p>
        
        {/* Debug info */}
        <div className="mt-4 text-xs text-gray-400">
          <p>Transaction ID: {transactionId}</p>
          <p>WebSocket: {window.Echo ? 'Disponible' : 'No disponible'}</p>
        </div>
      </div>
    );
  }

  // Pago aprobado
  if (status === 'approved') {
    return (
      <div className="text-center p-8">
        <div className="text-green-600 mb-4">
          <svg className="w-16 h-16 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <h2 className="text-2xl font-bold mb-4">¡Pago Aprobado!</h2>
        <p className="mb-6">Tu pago ha sido procesado exitosamente.</p>
        {transaction && (
          <div className="mb-6 p-4 bg-green-50 rounded-lg">
            <p className="font-semibold">Referencia: {transaction.reference}</p>
            <p className="text-sm">Monto: ${transaction.amount}</p>
          </div>
        )}
        <button
          onClick={goToOrder}
          className="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded-lg transition duration-200"
        >
          Ver Mi Pedido
        </button>
        <p className="mt-4 text-sm text-gray-500">
          Serás redirigido automáticamente en 5 segundos...
        </p>
      </div>
    );
  }

  // Pago rechazado
  if (status === 'declined') {
    return (
      <div className="text-center p-8">
        <div className="text-red-600 mb-4">
          <svg className="w-16 h-16 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </div>
        <h2 className="text-2xl font-bold mb-4">Pago Rechazado</h2>
        <p className="mb-6">Tu pago no pudo ser procesado. Por favor intenta con otro método.</p>
        {transaction?.error_message && (
          <div className="mb-6 p-4 bg-red-50 rounded-lg">
            <p className="text-red-700">{transaction.error_message}</p>
          </div>
        )}
        <button
          onClick={goToCheckout}
          className="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg transition duration-200"
        >
          Intentar Nuevamente
        </button>
      </div>
    );
  }

  // Pago pendiente
  return (
    <div className="text-center p-8">
      <div className="text-yellow-600 mb-4">
        <svg className="w-16 h-16 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <h2 className="text-2xl font-bold mb-4">Pago Pendiente</h2>
      <p className="mb-6">Estamos esperando la confirmación de tu pago.</p>
      <p className="text-sm text-gray-500 mb-4">
        Esta página se actualizará automáticamente cuando recibamos la confirmación.
      </p>
      <button
        onClick={checkStatus}
        className="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-6 rounded-lg transition duration-200 mr-2"
      >
        Verificar Estado
      </button>
      <button
        onClick={goToCheckout}
        className="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg transition duration-200"
      >
        Volver al Checkout
      </button>
      
      {/* Debug info */}
      <div className="mt-8 text-xs text-gray-400">
        <p>Estado actual: {status}</p>
        <p>Transaction ID: {transactionId}</p>
        <p>Última verificación: {new Date().toLocaleTimeString()}</p>
      </div>
    </div>
  );
};

export default PaymentStatus;