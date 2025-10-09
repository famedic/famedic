import { ArrowRightIcon } from "@heroicons/react/20/solid";
import { Link } from "@inertiajs/react";
import { ShoppingCartIcon } from "@heroicons/react/24/solid";

export default function ShoppingCartBanner({ message, href }) {
	return (
		<div className="pointer-events-none fixed inset-x-0 bottom-0 sm:flex sm:justify-center sm:px-6 sm:pb-3">
			<Link
				href={href}
				className="pointer-events-auto flex items-center justify-between gap-x-6 bg-famedic-lime/85 px-6 py-2.5 backdrop-blur-sm sm:rounded-xl sm:py-3 sm:pl-4 sm:pr-3.5"
			>
				<ShoppingCartIcon className="h-6 w-6 fill-famedic-dark" />
				<p className="text-sm font-semibold leading-6 text-famedic-dark">
					{message}
				</p>
				<div className="-m-1.5 flex-none p-1.5">
					<span className="sr-only">Dismiss</span>
					<ArrowRightIcon
						aria-hidden="true"
						className="h-5 w-5 text-famedic-dark"
					/>
				</div>
			</Link>
		</div>
	);
}
