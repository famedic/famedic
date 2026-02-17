import React from 'react';

export default function EfevooPayLogo({ className = "size-4" }) {
    return (
        <svg 
            className={className} 
            viewBox="0 0 24 24" 
            fill="none" 
            xmlns="http://www.w3.org/2000/svg"
        >
            {/* Icono tipo tarjeta con "E" para Efevoo */}
            <rect x="3" y="6" width="18" height="12" rx="2" stroke="currentColor" strokeWidth="2" fill="none"/>
            <path d="M8 10H16" stroke="currentColor" strokeWidth="2" strokeLinecap="round"/>
            <path d="M8 14H14" stroke="currentColor" strokeWidth="2" strokeLinecap="round"/>
            <text 
                x="12" 
                y="19" 
                textAnchor="middle" 
                fontSize="8" 
                fontWeight="bold" 
                fill="currentColor"
                fontFamily="Arial, sans-serif"
            >
                Efevoo
            </text>
        </svg>
    );
}