import { useEffect } from "react";
import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import ContactDrawerSection from "./ContactDrawerSection";

function SectionSkeleton() {
	return (
		<div className="space-y-2" aria-busy="true">
			{Array.from({ length: 3 }).map((_, i) => (
				<div
					key={i}
					className="h-14 animate-pulse rounded-lg bg-zinc-100 dark:bg-zinc-800"
				/>
			))}
		</div>
	);
}

function EmptyState({ message = "Sin registros." }) {
	return <Text className="text-sm text-zinc-500">{message}</Text>;
}

/**
 * Hook de montaje: pide la sección una vez que el drawer base está listo.
 */
export function useRequestWhenReady(ready, onRequest) {
	useEffect(() => {
		if (!ready || !onRequest) {
			return;
		}
		onRequest();
	}, [ready, onRequest]);
}

export function ContactSectionShell({
	title,
	description,
	truth = "disponible",
	ready = false,
	loading = false,
	items = null,
	emptyMessage,
	onRequest,
	children,
}) {
	useRequestWhenReady(ready, onRequest);

	return (
		<ContactDrawerSection title={title} description={description} truth={truth}>
			{!ready || (loading && !items) ? (
				<SectionSkeleton />
			) : !items?.length ? (
				<EmptyState message={emptyMessage} />
			) : (
				children(items)
			)}
		</ContactDrawerSection>
	);
}

export function MetaRow({ label, value }) {
	return (
		<div className="flex justify-between gap-3 text-xs">
			<span className="text-zinc-400">{label}</span>
			<span className="text-right font-medium text-zinc-800 dark:text-zinc-200">
				{value}
			</span>
		</div>
	);
}

export function ItemCard({ title, badge, badgeColor = "zinc", children }) {
	return (
		<article className="rounded-lg border border-zinc-200 bg-zinc-50/80 p-3 dark:border-zinc-700 dark:bg-zinc-950/40">
			<div className="mb-2 flex flex-wrap items-center justify-between gap-2">
				<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					{title}
				</p>
				{badge ? <Badge color={badgeColor}>{badge}</Badge> : null}
			</div>
			<div className="space-y-1">{children}</div>
		</article>
	);
}
