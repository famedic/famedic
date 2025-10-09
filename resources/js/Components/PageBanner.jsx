import { useState } from "react";
import { XMarkIcon } from "@heroicons/react/20/solid";

export default function PageBanner({ text, href, onClick }) {
	const [visible, setVisible] = useState(true);

	if (!visible || !text || (!href && !onClick)) return null;

	return (
		<div className="flex items-center gap-x-6 bg-famedic-darker px-6 py-2.5 sm:px-3.5 sm:before:flex-1 dark:bg-white">
			<p className="text-sm/6 text-white">
				{onClick ? (
					<button
						type="button"
						onClick={onClick}
						className="inline-flex items-center text-white dark:text-famedic-darker"
					>
						<strong className="font-semibold">{text}</strong>
						<span aria-hidden="true" className="ml-1">
							&rarr;
						</span>
					</button>
				) : (
					<a href={href}>
						<strong className="font-semibold">{text}</strong>
						<span aria-hidden="true" className="ml-1">
							&rarr;
						</span>
					</a>
				)}
			</p>
			<div className="flex flex-1 justify-end">
				<button
					type="button"
					className="-m-3 p-3 focus-visible:outline-offset-[-4px]"
					onClick={() => setVisible(false)}
				>
					<span className="sr-only">Dismiss</span>
					<XMarkIcon
						aria-hidden="true"
						className="size-5 text-white dark:text-famedic-darker"
					/>
				</button>
			</div>
		</div>
	);
}
