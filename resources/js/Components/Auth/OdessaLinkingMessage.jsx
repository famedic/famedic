import { useEffect, useState } from "react";
import { Text } from "@/Components/Catalyst/text";
import { ClockIcon } from "@heroicons/react/24/outline";
import { router } from "@inertiajs/react";

export default function OdessaLinkingMessage({ secondsLeft }) {
	const [timer, setTimer] = useState(secondsLeft);

	useEffect(() => {
		if (timer <= 0) {
			router.get(route("welcome"));
		}

		const interval = setInterval(() => {
			setTimer((prev) => Math.max(prev - 1, 0));
		}, 1000);

		return () => clearInterval(interval);
	}, [timer]);

	if (timer)
		return (
			<Text>
				Para mantener tu{" "}
				<span className="font-bold text-famedic-light dark:text-famedic-lime">
					cuenta segura
				</span>
				, cuentas con{" "}
				<span className="flex items-center font-bold text-famedic-light dark:text-famedic-lime">
					<span
						className={`mr-1 flex items-center space-x-1 ${
							timer > 60
								? "text-famedic-light dark:text-famedic-lime"
								: "text-red-600"
						}`}
					>
						<ClockIcon className="size-5" />
						<span className="font-bold">
							{Math.floor(timer / 60)}:
							{timer % 60 < 10 ? `0${timer % 60}` : timer % 60}
						</span>
					</span>
					para completar tu registro
				</span>
			</Text>
		);

	return null;
}
