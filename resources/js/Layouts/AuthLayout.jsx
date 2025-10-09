import ApplicationLogo from "@/Components/ApplicationLogo";
import { Head } from "@inertiajs/react";
import { Heading } from "@/Components/Catalyst/heading";
import Notification from "@/Components/Notification";
import OdessaLogo from "@/Components/OdessaLogo";
import { LinkIcon } from "@heroicons/react/20/solid";
import { Button } from "@/Components/Catalyst/button";
import useTrackingEvents from "@/Hooks/useTrackingEvents";

export default function AuthLayout({
	title,
	header,
	children,
	showOdessaLogo = false,
}) {
	useTrackingEvents();

	return (
		<>
			<Head title={title} />

			<div className="flex min-h-screen flex-1 bg-white dark:bg-slate-950">
				<div className="flex flex-1 flex-col justify-center px-4 py-12 sm:px-6 lg:flex-none lg:px-20 xl:px-24">
					<div className="mx-auto w-full max-w-sm lg:w-96">
						<Button
							plain
							href={route("welcome")}
							className="mb-8 flex items-center gap-3"
						>
							{showOdessaLogo && (
								<>
									<OdessaLogo className="h-10 w-auto" />
									<LinkIcon className="h-6 w-6 text-gray-500" />
								</>
							)}
							<ApplicationLogo className="h-10 w-auto" />
							<Heading>Famedic</Heading>
						</Button>
						{header && <div className="space-y-2">{header}</div>}

						<div className="mt-10">{children}</div>
					</div>
				</div>
				<div className="relative hidden w-0 flex-1 lg:block">
					<img
						alt=""
						src="https://images.pexels.com/photos/3902881/pexels-photo-3902881.jpeg"
						className="absolute inset-0 h-full w-full object-cover"
					/>
				</div>
			</div>

			<Notification />
		</>
	);
}
