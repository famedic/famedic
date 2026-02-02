export default function SimpleField({ children, className = '' }) {
    return (
        <div className={`space-y-3 ${className}`}>
            {children}
        </div>
    );
}