import { StackedLayout } from "@/Components/Catalyst/stacked-layout";
import SideBar from "@/Layouts/FamedicLayout/SideBar";
import NavBar from "@/Layouts/FamedicLayout/NavBar";
import Notification from "@/Components/Notification";
import { Head, usePage } from "@inertiajs/react";
import { useEffect } from "react";
import confettiLib from "canvas-confetti";
import HelpBubble from "@/Components/Catalyst/HelpBubble";
import PageBanner from "@/Components/PageBanner";
import useTrackingEvents from "@/Hooks/useTrackingEvents";

export default function FamedicLayout({
	title,
	children,
	banner = null,
	hasShoppingCartBanner = false,
}) {
	useTrackingEvents();

	const { confetti } = usePage().props;

	useEffect(() => {
		if (confetti) {
			confettiLib({
				particleCount: 100,
				spread: 70,
				origin: { y: 0.6 },
			});
		}
	}, [confetti]);

	return (
		<>
			{banner && (
				<PageBanner
					text={banner.text}
					href={banner.href}
					onClick={banner.onClick}
				/>
			)}
			<Head title={title} />
			<StackedLayout navbar={<NavBar />} sidebar={<SideBar />}>
				<div className="space-y-6 pt-6 lg:space-y-8 lg:pt-10">
					{children}
				</div>
			</StackedLayout>
			<HelpBubble
				className={hasShoppingCartBanner ? "max-sm:mb-10" : ""}
			/>
			<Notification />
		</>
	);
}
