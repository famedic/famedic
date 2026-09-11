import { BeakerIcon, ClockIcon, HeartIcon, ShieldCheckIcon } from "@heroicons/react/20/solid";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";

const ICONS = {
	shield: ShieldCheckIcon,
	heart: HeartIcon,
	lab: BeakerIcon,
	clock: ClockIcon,
};

export default function CampaignEditorialSection({ editorial = {} }) {
	const items = Array.isArray(editorial.items) ? editorial.items.slice(0, 4) : [];

	if (!editorial.eyebrow && !editorial.title && !editorial.body && items.length === 0) {
		return null;
	}

	return (
		<section className="grid gap-6 lg:grid-cols-[0.85fr_1.15fr]">
			<div>
				{editorial.eyebrow && (
					<Text className="text-sm font-semibold uppercase text-famedic-dark dark:text-famedic-light">
						{editorial.eyebrow}
					</Text>
				)}
				{editorial.title && <Heading level={2} className="mt-2">{editorial.title}</Heading>}
				{editorial.body && (
					<Text className="mt-4 whitespace-pre-line text-zinc-600 dark:text-zinc-400">{editorial.body}</Text>
				)}
				<Text className="mt-4 text-sm text-zinc-500">
					Esta información es orientativa y no sustituye una valoración médica profesional.
				</Text>
			</div>
			{items.length > 0 && (
				<div className="grid gap-3 sm:grid-cols-2">
					{items.map((item, index) => {
						const Icon = ICONS[item.icon] || ShieldCheckIcon;
						return (
							<div key={`${item.title}-${index}`} className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
								<Icon className="size-5 text-famedic-dark dark:text-famedic-light" />
								<Subheading className="mt-3">{item.title}</Subheading>
								{item.description && (
									<Text className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{item.description}</Text>
								)}
							</div>
						);
					})}
				</div>
			)}
		</section>
	);
}
