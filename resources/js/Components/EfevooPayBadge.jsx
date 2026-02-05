import React from 'react';
import { Badge } from "@/Components/Catalyst/badge";
import EfevooPayLogo from "./EfevooPayLogo";

export default function EfevooPayBadge({ children = "Efevoo Pay", className = "" }) {
    return (
        <Badge color="purple" className={className}>
            <EfevooPayLogo className="size-4" />
            {children}
        </Badge>
    );
}