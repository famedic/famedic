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
		<Sidebar className={cardClasses(className)}>
			<SidebarBody>
				<SidebarSection>
					{links.map(({ label, url, current, IconComponent }) => {
						return (
							<SidebarItem
								current={current}
								key={label}
								href={url}
							>
								{IconComponent && <IconComponent />}
								<SidebarLabel>{label}</SidebarLabel>
							</SidebarItem>
						);
					})}
				</SidebarSection>
			</SidebarBody>
		</Sidebar>
	);
}
