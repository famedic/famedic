import { CheckCircleIcon } from "@heroicons/react/24/solid";

export default function CheckoutProgress({ steps, currentStep }) {
    return (
        <div className="mb-8">
            <div className="flex items-center justify-between">
                {steps.map((step, index) => (
                    <div key={step.id} className="flex flex-1 items-center">
                        <div className="flex flex-col items-center">
                            <div className={`
                                flex h-10 w-10 items-center justify-center rounded-full
                                ${step.completed ? 'bg-green-100' : currentStep === step.id ? 'bg-blue-100' : 'bg-gray-100'}
                                transition-all duration-300
                            `}>
                                {step.completed ? (
                                    <CheckCircleIcon className="h-6 w-6 text-green-600" />
                                ) : (
                                    <span className={`
                                        text-sm font-medium
                                        ${currentStep === step.id ? 'text-blue-600' : 'text-gray-400'}
                                    `}>
                                        {index + 1}
                                    </span>
                                )}
                            </div>
                            <span className="mt-2 text-xs font-medium text-gray-600">
                                {step.name}
                            </span>
                        </div>
                        
                        {index < steps.length - 1 && (
                            <div className={`
                                h-0.5 flex-1 mx-4
                                ${step.completed ? 'bg-green-600' : currentStep === step.id ? 'bg-blue-200' : 'bg-gray-200'}
                                transition-all duration-300
                            `} />
                        )}
                    </div>
                ))}
            </div>
            
            {currentStep === 'contact' && (
                <div className="mt-4 p-3 bg-blue-50 rounded-lg border border-blue-100">
                    <p className="text-sm text-blue-700">
                        <strong>Primer paso:</strong> Selecciona o crea un paciente para continuar
                    </p>
                </div>
            )}
            
            {currentStep === 'address' && (
                <div className="mt-4 p-3 bg-blue-50 rounded-lg border border-blue-100">
                    <p className="text-sm text-blue-700">
                        <strong>Segundo paso:</strong> Ahora selecciona una dirección de envío
                    </p>
                </div>
            )}
            
            {currentStep === 'payment' && (
                <div className="mt-4 p-3 bg-blue-50 rounded-lg border border-blue-100">
                    <p className="text-sm text-blue-700">
                        <strong>Tercer paso:</strong> Por último, elige tu método de pago
                    </p>
                </div>
            )}
        </div>
    );
}