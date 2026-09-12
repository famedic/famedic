import { BeakerIcon, ClockIcon, HeartIcon, ShieldCheckIcon } from "@heroicons/react/20/solid";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";

const ICONS = {
	shield: ShieldCheckIcon,
	heart: HeartIcon,
	lab: BeakerIcon,
	clock: ClockIcon,
};

export default function CampaignEditorialSection({ editorial = {}, image = null }) {
	const items = Array.isArray(editorial.items) ? editorial.items.slice(0, 4) : [];
	const hasCopy = Boolean(editorial.eyebrow || editorial.title || editorial.body || items.length);
	const callout = typeof editorial.callout === "string" ? editorial.callout.trim() : "Decide con informacion clara antes de comprar.";

	if (!hasCopy && !image) return null;

	return (
		<section className={`grid gap-10 lg:gap-16 ${image ? "lg:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)] lg:items-start" : "lg:max-w-3xl"}`}>
			<div className="space-y-6">
				<div>
					{editorial.eyebrow && (
						<Text className="text-sm font-semibold uppercase text-sky-700">
							{editorial.eyebrow}
						</Text>
					)}
					{editorial.title && (
						<Heading level={2} className="mt-2 font-poppins text-3xl font-semibold text-famedic-darker sm:text-4xl">
							{editorial.title}
						</Heading>
					)}
					{editorial.body && (
						<Text className="mt-4 max-w-2xl whitespace-pre-line text-base leading-7 text-zinc-700">
							{editorial.body}
						</Text>
					)}
				</div>
				{items.length > 0 && (
					<div className="grid gap-5">
						{items.map((item, index) => {
							const Icon = ICONS[item.icon] || ShieldCheckIcon;
							return (
								<div key={`${item.title}-${index}`} className="flex gap-4">
									<span className="flex size-12 shrink-0 items-center justify-center rounded-full bg-sky-50 text-sky-800 ring-1 ring-sky-100">
										<Icon className="size-6" />
									</span>
									<div>
										<Subheading className="text-base font-semibold text-famedic-darker">
											{item.title}
										</Subheading>
										{item.description && (
											<Text className="mt-1 text-sm leading-6 text-zinc-600">{item.description}</Text>
										)}
									</div>
								</div>
							);
						})}
					</div>
				)}
				<div className="rounded-2xl bg-sky-50 px-5 py-4 text-sm leading-6 text-sky-950 ring-1 ring-sky-100">
					Esta información es orientativa y no sustituye una valoración médica profesional.
				</div>
			</div>
			{image && (
				<div className="relative overflow-hidden rounded-[22px] bg-zinc-100 shadow-sm ring-1 ring-zinc-200">
					<img
						src={image.url}
						alt={image.alt || editorial.title || ""}
						className="aspect-[5/4] w-full object-cover lg:h-[540px] lg:aspect-auto"
					/>
					{callout && (
						<div className="absolute bottom-5 left-1/2 w-[calc(100%-3rem)] max-w-md -translate-x-1/2 rounded-xl bg-white/94 px-5 py-4 text-center text-sm font-semibold leading-6 text-famedic-darker shadow-sm ring-1 ring-white/80 backdrop-blur">
							{callout}
						</div>
					)}
				</div>
			)}
		</section>
	);
}
