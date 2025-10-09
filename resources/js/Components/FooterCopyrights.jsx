export default function FooterCopyrights({ className }) {
	return (
		<Text className={className}>
			<span className="text-xs">
				<span className="font-poppins">Famedic</span>
				&copy; {new Date().getFullYear()} <span> · </span>Todos los
				derechos reservados.
			</span>
		</Text>
	);
}

import { Text } from "@/Components/Catalyst/text";
