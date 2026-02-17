// resources/js/Components/Form/SimpleInput.jsx
import { forwardRef } from 'react';

const SimpleInput = forwardRef(({ 
    className = '', 
    type = 'text', 
    error,
    label,
    description,
    ...props 
}, ref) => {
    return (
        <div>
            {label && (
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    {label}
                </label>
            )}
            <input
                ref={ref}
                type={type}
                className={`block w-full rounded-lg border px-3 py-2 text-sm 
                          placeholder:text-gray-400 focus:outline-none 
                          focus:ring-2 disabled:cursor-not-allowed 
                          disabled:bg-gray-50 disabled:text-gray-500 
                          dark:bg-gray-800 dark:text-gray-100 dark:placeholder:text-gray-500 
                          ${error 
                            ? 'border-red-300 focus:border-red-500 focus:ring-red-500/25 dark:border-red-700' 
                            : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500/25 dark:border-gray-600'
                          } ${className}`}
                {...props}
            />
            {error && (
                <p className="mt-1 text-sm text-red-600 dark:text-red-400">
                    {error}
                </p>
            )}
            {description && !error && (
                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {description}
                </p>
            )}
        </div>
    );
});

SimpleInput.displayName = 'SimpleInput';

export default SimpleInput;