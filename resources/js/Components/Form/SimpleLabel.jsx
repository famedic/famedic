export default function SimpleLabel({ children, className = '' }) {
    return (
        <label className={`block text-sm font-medium text-gray-700 dark:text-gray-300 ${className}`}>
            {children}
        </label>
    );
}
