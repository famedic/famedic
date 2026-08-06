import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import SuiteBadge from "./SuiteBadge";

export default function SuiteHeader({
	emoji,
	title,
	subtitle,
	badge = "BETA",
	badgeVariant = "beta",
	generatedAt,
	userName,
	breadcrumbs = [],
}) {
	return (
		<header className="space-y-3">
			{breadcrumbs.length > 0 ? (
				<nav className="flex flex-wrap items-center gap-1.5 text-xs text-zinc-500">
					{breadcrumbs.map((crumb, index) => (
						<span key={crumb.label} className="inline-flex items-center gap-1.5">
							{index > 0 ? <span className="text-zinc-300">/</span> : null}
							{crumb.href ? (
								<a
									href={crumb.href}
									className="hover:text-zinc-800 dark:hover:text-zinc-200"
								>
									{crumb.label}
								</a>
							) : (
								<span className="font-medium text-zinc-700 dark:text-zinc-300">
									{crumb.label}
								</span>
							)}
						</span>
					))}
				</nav>
			) : null}

			<div className="flex flex-wrap items-start justify-between gap-4">
				<div className="max-w-3xl">
					<div className="flex flex-wrap items-center gap-2">
						{emoji ? (
							<span className="text-2xl" aria-hidden="true">
								{emoji}
							</span>
						) : null}
						{badge ? (
							<SuiteBadge variant={badgeVariant} className="ml-0">
								{badge}
							</SuiteBadge>
						) : null}
					</div>
					<Heading className="mt-2 !text-3xl tracking-tight">{title}</Heading>
					{subtitle ? (
						<Text className="mt-2 text-base text-zinc-500 dark:text-zinc-400">
							{subtitle}
						</Text>
					) : null}
				</div>
				<div className="space-y-1 text-right text-xs text-zinc-500">
					{userName ? <p>{userName}</p> : null}
					{generatedAt ? <p>Actualizado {generatedAt}</p> : null}
				</div>
			</div>
		</header>
	);
}
