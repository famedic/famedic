import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import CampaignBrandLogo from "./CampaignBrandLogo";

export default function CampaignHero({
	content,
	brand,
	category,
	starts,
	ends,
	primaryAction,
	secondaryAction,
	actionButtonProps,
	variant = "split",
}) {
	const image = content?.hero_image ? (
		<div className="overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
			<img
				src={content.hero_image}
				alt={content.hero_image_alt || content.title || ""}
				className={variant === "wide" ? "max-h-[28rem] w-full object-cover" : "h-full max-h-96 w-full object-cover"}
			/>
		</div>
	) : (
		<div className="hidden min-h-64 rounded-lg bg-zinc-100 dark:bg-zinc-800 lg:block" />
	);

	const copy = (
		<div className="space-y-4">
			<CampaignBrandLogo brand={brand} showLogo={Boolean(content?.show_brand_logo)} />
			{content?.eyebrow && <Badge color="lime">{content.eyebrow}</Badge>}
			<Heading>{content?.title}</Heading>
			{content?.subtitle && (
				<Subheading className="text-zinc-600 dark:text-zinc-300">
					{content.subtitle}
				</Subheading>
			)}
			{content?.description && (
				<Text className="max-w-2xl whitespace-pre-line text-zinc-600 dark:text-zinc-400">
					{content.description}
				</Text>
			)}
			{content?.show_campaign_dates && (starts || ends) && (
				<Text className="text-sm text-zinc-500">
					Vigencia{starts ? `: desde ${starts}` : ""}{ends ? ` hasta ${ends}` : ""}
				</Text>
			)}
			{category?.name && <Badge color="sky">{category.name}</Badge>}
			<div className="flex flex-wrap gap-3 pt-2">
				{primaryAction?.url && (
					<Button {...actionButtonProps(primaryAction.url, { color: "lime" })}>
						{primaryAction.label}
					</Button>
				)}
				{secondaryAction?.url && (
					<Button {...actionButtonProps(secondaryAction.url, { outline: true })}>
						{secondaryAction.label}
					</Button>
				)}
			</div>
		</div>
	);

	if (variant === "wide") {
		return (
			<section className="space-y-6">
				{image}
				<div className="rounded-lg bg-famedic-dark p-6 text-white">
					{copy}
				</div>
			</section>
		);
	}

	if (variant === "compact") {
		return (
			<section className="grid gap-6 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900 lg:grid-cols-[0.8fr_1.2fr]">
				{copy}
				{image}
			</section>
		);
	}

	return (
		<section className="grid gap-8 lg:grid-cols-[1.2fr_0.8fr] lg:items-center">
			{copy}
			{image}
		</section>
	);
}
