import {
	Sidebar,
	SidebarBody,
	SidebarItem,
	SidebarSection,
	SidebarLabel,
} from "@/Components/Catalyst/sidebar";
import { cardClasses } from "@/Components/Card";
import MobileBottomNav from "@/Components/MobileNav/MobileBottomNav";
import { usePage } from "@inertiajs/react";

export default function VerticalNavbar({ className, links, enableMobileBottomNav = false }) {
	const { userNavigation = [] } = usePage().props;

	return (
		<>
			{/* Sidebar normal en desktop */}
			<Sidebar className={`${cardClasses(className)} hidden lg:block`}>
				<SidebarBody>
					<SidebarSection>
						{links.map(({ label, url, current, IconComponent }) => {
							return (
								<SidebarItem current={current} key={label} href={url}>
									{IconComponent && <IconComponent />}
									<SidebarLabel>{label}</SidebarLabel>
								</SidebarItem>
							);
						})}
					</SidebarSection>
				</SidebarBody>
			</Sidebar>

			{enableMobileBottomNav ? (
				<MobileBottomNav userNavigation={userNavigation} />
			) : (
				<>
					<nav className="fixed bottom-0 left-0 right-0 z-50 border-t border-gray-200 bg-white shadow-lg lg:hidden dark:border-slate-800 dark:bg-slate-900">
						<div className="flex h-16 items-center justify-around">
							{links.map(({ label, url, current, IconComponent }) => {
								return (
									<a
										key={label}
										href={url}
										className={`
											flex h-full flex-1 flex-col items-center justify-center
											transition-colors duration-200
											${
												current
													? "-mt-[2px] border-t-2 border-blue-600 text-blue-600"
													: "text-gray-600 hover:text-blue-500 dark:text-slate-400"
											}
										`}
									>
										{IconComponent && <IconComponent className="h-5 w-5" />}
										<span className="mt-1 text-xs">{label}</span>
									</a>
								);
							})}
						</div>
					</nav>
					<div className="h-16 shrink-0 lg:hidden" aria-hidden />
				</>
			)}
		</>
	);
}
