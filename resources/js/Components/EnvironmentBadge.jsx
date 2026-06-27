import { Badge } from "@/Components/Catalyst/badge";
import { usePage } from "@inertiajs/react";

const colorMap = {
    local: "sky",
    staging: "amber",
    testing: "purple",
    qa: "purple",
};

export default function EnvironmentBadge({
    label: labelProp,
    className = "",
}) {
    const { props } = usePage();
    const show =
        props.showAppEnvBadge ??
        (props.appEnv && props.appEnv !== "production");
    const label =
        labelProp ??
        props.appEnvLabel ??
        (props.appEnv ? String(props.appEnv).toUpperCase() : "");

    if (!show || !label) {
        return null;
    }

    const envKey = String(props.appEnv ?? "").toLowerCase();
    const color = colorMap[envKey] ?? "zinc";

    return (
        <Badge
            color={color}
            className={`!text-[10px] !font-semibold uppercase tracking-wider ${className}`}
        >
            {label}
        </Badge>
    );
}
