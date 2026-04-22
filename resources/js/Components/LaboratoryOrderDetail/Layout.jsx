export default function Layout({ header, tabs, main, sidebar }) {
	return (
		<div className="w-full min-w-0 max-w-full space-y-6 lg:space-y-8">
			<div className="min-w-0 max-w-full">{header}</div>
			<div className="min-w-0 max-w-full overflow-hidden rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5">
				{tabs}
			</div>
			<div className="grid min-w-0 max-w-full gap-6 xl:grid-cols-10">
				<section className="min-w-0 max-w-full space-y-6 xl:col-span-7">{main}</section>
				<aside className="min-w-0 max-w-full space-y-6 xl:col-span-3">{sidebar}</aside>
			</div>
		</div>
	);
}
