import { usePage } from "@inertiajs/react";
import { Transition } from "@headlessui/react";
import {
	CheckCircleIcon,
	XMarkIcon,
	ExclamationTriangleIcon,
} from "@heroicons/react/24/solid";
import { useState, useEffect, useRef } from "react";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import clsx from "clsx";

export default function NotificationComponent() {
	const { flashMessage } = usePage().props;
	const [dismissed, setDismissed] = useState(false);
	const timeoutRef = useRef(null);

	useEffect(() => {
		if (flashMessage) {
			setDismissed(false);
			if (timeoutRef.current) {
				clearTimeout(timeoutRef.current);
			}
			timeoutRef.current = setTimeout(() => {
				setDismissed(true);
			}, 8000);
		}

		return () => {
			if (timeoutRef.current) {
				clearTimeout(timeoutRef.current);
			}
		};
	}, [flashMessage]);

	return (
		<>
			<div
				aria-live="assertive"
				className="pointer-events-none fixed inset-0 z-10 flex items-end px-4 py-6 sm:items-start sm:p-6"
			>
				<div className="flex w-full flex-col items-center space-y-4 sm:items-end">
					<Transition
						show={!!flashMessage && !dismissed}
						enter="transition ease-out duration-300 transform"
						enterFrom="opacity-0 translate-y-2 sm:translate-y-0 sm:translate-x-2"
						enterTo="opacity-100 translate-y-0 sm:translate-x-0"
						leave="transition ease-in duration-100 transform"
						leaveFrom="opacity-100 translate-y-0 sm:translate-x-0"
						leaveTo="opacity-0 translate-y-2 sm:translate-y-0 sm:translate-x-2"
					>
						<div
							className={clsx(
								flashMessage?.type === "success"
									? "bg-famedic-darker bg-opacity-90 dark:bg-famedic-lime"
									: "bg-red-200",
								"pointer-events-auto w-full max-w-sm overflow-hidden rounded-lg bg-famedic-darker shadow-lg",
							)}
						>
							<div className="p-4">
								<div className="flex items-start">
									<div className="flex-shrink-0">
										{flashMessage?.type === "success" && (
											<CheckCircleIcon
												aria-hidden="true"
												className="h-6 w-6 fill-famedic-lime dark:fill-famedic-darker"
											/>
										)}

										{flashMessage?.type === "error" && (
											<ExclamationTriangleIcon
												aria-hidden="true"
												className="h-6 w-6 fill-red-700"
											/>
										)}
									</div>
									<div className="ml-3 w-0 flex-1">
										<Text>
											<span
												className={
													flashMessage?.type ===
													"success"
														? "text-white dark:text-famedic-darker"
														: "text-red-700"
												}
											>
												{flashMessage?.message}
											</span>
										</Text>
									</div>
									<div className="ml-4 flex flex-shrink-0">
										<Button
											plain
											type="button"
											onClick={() => setDismissed(true)}
										>
											<XMarkIcon
												aria-hidden="true"
												className={
													flashMessage?.type ===
													"success"
														? "fill-white dark:fill-famedic-darker"
														: "fill-red-700"
												}
											/>
										</Button>
									</div>
								</div>
							</div>
						</div>
					</Transition>
				</div>
			</div>
		</>
	);
}
