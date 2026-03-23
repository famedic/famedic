import {
	Sidebar,
	SidebarBody,
	SidebarItem,
	SidebarSection,
	SidebarLabel,
} from "@/Components/Catalyst/sidebar";
import { cardClasses } from "@/Components/Card";

export default function VerticalNavbar({ className, links }) {
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

			{/* Bottom navigation en móvil */}
			<nav className="lg:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 shadow-lg z-50">
				<div className="flex justify-around items-center h-16">
					{links.map(({ label, url, current, IconComponent }) => {
						return (
							<a
								key={label}
								href={url}
								className={`
									flex flex-col items-center justify-center flex-1 h-full
									transition-colors duration-200
									${current 
										? "text-blue-600 border-t-2 border-blue-600 -mt-[2px]" 
										: "text-gray-600 hover:text-blue-500"
									}
								`}
							>
								{IconComponent && <IconComponent className="w-5 h-5" />}
								<span className="text-xs mt-1">{label}</span>
							</a>
						);
					})}
				</div>
			</nav>

			{/* Espacio para evitar que el contenido quede detrás del bottom nav */}
			<div className="lg:hidden h-16" />
		</>
	);
}