import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import CampaignBrandLogo from "./CampaignBrandLogo";
import {
	ArrowRightIcon,
	ClockIcon,
	CreditCardIcon,
	ShieldCheckIcon,
	SparklesIcon,
} from "@heroicons/react/20/solid";

const BENEFITS = [
	{ label: "Precios preferenciales", description: "por campaña", icon: SparklesIcon },
	{ label: "Sucursales cercanas", description: "en todo México", icon: CreditCardIcon },
	{ label: "Compra segura", description: "y confiable", icon: ShieldCheckIcon },
];

function SecondaryHeroAction({ action, actionButtonProps, stack = false, editorial = false }) {
	if (!action?.url) return null;

	const props = actionButtonProps(action.url);
	const Element = props.href ? "a" : "button";

	return (
		<Element
			{...props}
			className={
				editorial
					? `inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-white/70 bg-white/12 px-4 py-2 text-sm font-semibold text-white shadow-none transition hover:bg-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-white active:bg-white/25 ${stack ? "w-full sm:w-auto" : ""} ${props.className || ""}`
					: `inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-famedic-darker transition hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime active:bg-zinc-100 ${stack ? "w-full sm:w-auto" : ""} ${props.className || ""}`
			}
		>
			{action.label}
			{editorial && <ArrowRightIcon className="size-4" aria-hidden="true" />}
		</Element>
	);
}

function HeroActions({ primaryAction, secondaryAction, actionButtonProps, stack = false, editorial = false }) {
	return (
		<div className={`flex gap-3 pt-2 ${stack ? "flex-col sm:flex-row" : "flex-wrap"}`}>
			{primaryAction?.url && (
				<Button
					{...actionButtonProps(primaryAction.url, {
						color: "lime",
						className: stack ? "w-full sm:w-auto" : undefined,
					})}
				>
					{primaryAction.label}
				</Button>
			)}
			<SecondaryHeroAction
				action={secondaryAction}
				actionButtonProps={actionButtonProps}
				stack={stack}
				editorial={editorial}
			/>
		</div>
	);
}

function HeroImage({ content, className = "", rounded = "rounded-2xl" }) {
	if (!content?.hero_image) {
		return (
			<div
				className={`flex min-h-64 items-center justify-center ${rounded} bg-gradient-to-br from-emerald-50 to-sky-50 ring-1 ring-zinc-200 ${className}`}
				aria-hidden="true"
			>
				<div className="size-24 rounded-full bg-white/75 ring-1 ring-emerald-100" />
			</div>
		);
	}

	return (
		<img
			src={content.hero_image}
			alt={content.hero_image_alt || content.title || ""}
			className={`h-full w-full object-cover ${className}`}
		/>
	);
}

function BenefitRow({ dark = false }) {
	if (!dark) {
		return (
			<div className="grid cursor-default gap-3 text-sm sm:grid-cols-3">
				{BENEFITS.map((benefit) => {
					const Icon = benefit.icon;

					return (
						<div
							key={benefit.label}
							className="flex items-center gap-3 rounded-2xl bg-white/90 px-4 py-3 text-famedic-darker shadow-sm ring-1 ring-sky-100 backdrop-blur"
						>
							<span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-sky-50 text-sky-800 ring-1 ring-sky-100">
								<Icon className="size-5" aria-hidden="true" />
							</span>
							<span className="min-w-0">
								<span className="block font-semibold leading-5">{benefit.label}</span>
								<span className="block text-xs leading-5 text-slate-500">{benefit.description}</span>
							</span>
						</div>
					);
				})}
			</div>
		);
	}

	return (
		<div
			className={`grid cursor-default gap-0 overflow-hidden rounded-xl text-sm font-semibold sm:grid-cols-3 ${
				dark
					? "bg-white/12 text-white ring-1 ring-white/18"
					: "bg-white/70 text-famedic-darker ring-1 ring-emerald-100"
			}`}
		>
			{BENEFITS.map((benefit, index) => {
				const Icon = benefit.icon;

				return (
					<div
						key={benefit.label}
						className={`flex items-center justify-center gap-2 px-3 py-3 ${
							index > 0 ? "border-t border-white/18 sm:border-l sm:border-t-0" : ""
						}`}
					>
						<Icon className="size-4 shrink-0" aria-hidden="true" />
						<span>{benefit.label}</span>
					</div>
				);
			})}
		</div>
	);
}

function campaignDaysLeft(endsAt) {
	if (!endsAt) return null;
	const end = new Date(endsAt);
	if (Number.isNaN(end.getTime())) return null;
	const diff = end.getTime() - Date.now();
	if (diff <= 0) return 0;
	return Math.ceil(diff / 86400000);
}

function CampaignMeta({ content, category, starts, ends, endsAt, dark = false }) {
	const daysLeft = content?.show_campaign_dates ? campaignDaysLeft(endsAt) : null;

	return (
		<div className="flex flex-wrap items-center gap-2">
			{content?.eyebrow && (
				<span className="inline-flex w-fit items-center rounded-full bg-famedic-lime px-3 py-1.5 text-xs font-semibold text-famedic-darker ring-1 ring-lime-200">
					{content.eyebrow}
				</span>
			)}
			{category?.name && <Badge color="sky">{category.name}</Badge>}
			{content?.show_campaign_dates && (starts || ends) && (
				<span className={`text-xs font-medium ${dark ? "text-white/75" : "text-zinc-500"}`}>
					Vigencia{starts ? `: desde ${starts}` : ""}{ends ? ` hasta ${ends}` : ""}
				</span>
			)}
			{content?.show_campaign_dates && !ends && (
				<span className={`inline-flex items-center gap-1 text-xs font-semibold ${dark ? "text-white" : "text-famedic-darker"}`}>
					<ClockIcon className="size-4" aria-hidden="true" />
					Campaña permanente
				</span>
			)}
			{Number.isInteger(daysLeft) && (
				<span className="inline-flex items-center gap-1 rounded-full bg-white px-3 py-1 text-xs font-semibold text-famedic-darker">
					<ClockIcon className="size-4" aria-hidden="true" />
					{daysLeft === 0 ? "Último día" : `${daysLeft} día${daysLeft === 1 ? "" : "s"} restantes`}
				</span>
			)}
		</div>
	);
}

export default function CampaignHero({
	content,
	brand,
	category,
	starts,
	ends,
	endsAt,
	primaryAction,
	secondaryAction,
	actionButtonProps,
	variant = "conversion",
}) {
	if (variant === "editorial") {
		return (
			<section className="relative overflow-hidden rounded-[22px] bg-famedic-darker shadow-sm ring-1 ring-famedic-darker/10">
				<div className="relative h-64 overflow-hidden sm:h-80 lg:h-[620px]">
					<HeroImage content={content} className="absolute inset-0" rounded="rounded-none" />
					<div className="absolute inset-0 bg-gradient-to-r from-famedic-darker via-famedic-darker/82 to-famedic-darker/10" />
					<div className="relative flex h-full items-end lg:items-center">
						<div className="hidden w-[46%] min-w-[30rem] p-8 text-white lg:block">
							<CampaignBrandLogo brand={brand} showLogo={Boolean(content?.show_brand_logo)} size="lg" />
							<div className="mt-4 space-y-4">
								<CampaignMeta content={content} category={category} starts={starts} ends={ends} endsAt={endsAt} dark />
								<h1 className="font-poppins text-4xl font-semibold leading-tight text-white xl:text-5xl">
									{content?.title}
								</h1>
								{content?.description && (
									<p className="max-w-xl whitespace-pre-line text-base leading-7 text-white/82">
										{content.description}
									</p>
								)}
								<HeroActions
									primaryAction={primaryAction}
									secondaryAction={secondaryAction}
									actionButtonProps={actionButtonProps}
									editorial
								/>
								<BenefitRow dark />
							</div>
						</div>
					</div>
				</div>
				<div className="bg-famedic-darker p-5 text-white sm:p-6 lg:hidden">
					<CampaignBrandLogo brand={brand} showLogo={Boolean(content?.show_brand_logo)} size="sm" />
					<div className="mt-4 space-y-4">
						<CampaignMeta content={content} category={category} starts={starts} ends={ends} endsAt={endsAt} dark />
						<h1 className="font-poppins text-4xl font-semibold leading-tight text-white">
							{content?.title}
						</h1>
						{content?.description && (
							<p className="whitespace-pre-line text-base leading-7 text-white/82">
								{content.description}
							</p>
						)}
						<HeroActions
							primaryAction={primaryAction}
							secondaryAction={secondaryAction}
							actionButtonProps={actionButtonProps}
							stack
							editorial
						/>
						<BenefitRow dark />
					</div>
				</div>
			</section>
		);
	}

	if (variant === "catalog") {
		return (
			<section className="grid overflow-hidden rounded-[20px] bg-emerald-50 ring-1 ring-emerald-100 dark:bg-emerald-50 dark:ring-emerald-100 lg:grid-cols-[0.92fr_1.08fr] lg:items-center">
				<div className="space-y-4 p-5 sm:p-8 lg:py-9 lg:pl-12 lg:pr-8">
					<CampaignBrandLogo brand={brand} showLogo={Boolean(content?.show_brand_logo)} framed={false} />
					<CampaignMeta content={content} category={category} starts={starts} ends={ends} />
					<h1 className="max-w-xl font-poppins text-4xl font-semibold leading-tight text-famedic-darker dark:text-famedic-darker xl:text-5xl">
						{content?.title}
					</h1>
					{content?.subtitle && (
						<p className="max-w-xl text-base font-semibold leading-7 text-slate-700 dark:text-slate-700">{content.subtitle}</p>
					)}
					{content?.description && (
						<Text className="max-w-xl whitespace-pre-line text-base leading-7 text-slate-600 dark:text-slate-600">
							{content.description}
						</Text>
					)}
					<HeroActions
						primaryAction={primaryAction}
						secondaryAction={secondaryAction}
						actionButtonProps={actionButtonProps}
						stack
					/>
				</div>
				<div className="flex items-center p-4 sm:p-6 lg:py-7 lg:pl-4 lg:pr-10">
					<div className="relative aspect-[16/9] w-full overflow-hidden rounded-2xl shadow-sm ring-1 ring-slate-200">
						<HeroImage content={content} className="absolute inset-0" rounded="rounded-none" />
						<div className="absolute inset-0 bg-gradient-to-r from-famedic-darker/70 via-famedic-darker/18 to-transparent" />
						<div className="absolute bottom-6 left-6 max-w-[min(18rem,calc(100%-3rem))] text-white drop-shadow">
							<p className="font-poppins text-2xl font-semibold leading-7">Tecnología a tu salud</p>
							<p className="mt-1 text-sm leading-5 text-white/85">
								Diagnóstico confiable para cuidar lo que más importa.
							</p>
						</div>
					</div>
				</div>
			</section>
		);
	}

	return (
		<section className="relative grid overflow-hidden rounded-[22px] bg-white shadow-sm ring-1 ring-slate-100 lg:grid-cols-[0.95fr_1.05fr] lg:items-stretch">
			<div className="relative z-10 space-y-5 p-5 sm:p-8 lg:p-10">
				<CampaignBrandLogo brand={brand} showLogo={Boolean(content?.show_brand_logo)} />
				<CampaignMeta content={content} category={category} starts={starts} ends={ends} />
				<h1 className="font-poppins text-4xl font-semibold leading-tight text-famedic-darker sm:text-5xl lg:text-6xl">
					{content?.title}
				</h1>
				{content?.subtitle && (
					<p className="text-xl font-semibold text-famedic-dark">{content.subtitle}</p>
				)}
				{content?.description && (
					<Text className="max-w-2xl whitespace-pre-line text-base leading-7 text-zinc-700">
						{content.description}
					</Text>
				)}
				<HeroActions
					primaryAction={primaryAction}
					secondaryAction={secondaryAction}
					actionButtonProps={actionButtonProps}
					stack
				/>
				<BenefitRow />
			</div>
			<div className="min-h-72 overflow-hidden lg:min-h-[520px]">
				<HeroImage content={content} className="aspect-[4/3] lg:aspect-auto" rounded="rounded-none" />
			</div>
		</section>
	);
}
