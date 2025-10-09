import clsx from "clsx";

export default function LaboratoryBrandCard({ src, className = "" }) {
	return (
		<div
			className={clsx(
				"aspect-video flex items-center justify-center rounded bg-white",
				className,
			)}
		>
			<img src={src} className="scale-125 object-contain" />
		</div>
	);
}
