export default function Layout({ header, tabs, main, sidebar }) {
	return (
		<div className="w-full min-w-0 max-w-full space-y-6 lg:space-y-8">
			<div className="min-w-0 max-w-full">{header}</div>
			<div
				id="laboratory-order-tabs"
				className="sticky top-16 z-30 scroll-mt-16 min-w-0 max-w-full overflow-hidden rounded-2xl border border-zinc-200 bg-white/95 p-3 shadow-sm backdrop-blur-sm dark:border-slate-800 dark:bg-slate-900/95 sm:p-5"
			>
				{tabs}
			</div>
			<div className="grid min-w-0 max-w-full gap-6 xl:grid-cols-10">
				<section
					id="laboratory-order-tab-content"
					className="min-w-0 max-w-full scroll-mt-24 space-y-6 xl:col-span-7"
				>
					{main}
				</section>
				<aside className="min-w-0 max-w-full space-y-6 xl:col-span-3">{sidebar}</aside>
			</div>
		</div>
	);
}
