import FamedicLayout from "@/Layouts/FamedicLayout";
import Hero from "@/Pages/Home/Hero";
import CTA from "@/Pages/Home/CTA";
import QuickLinks from "@/Pages/Home/QuickLinks";
import { usePage } from "@inertiajs/react";

export default function Home() {
	const { auth, invitationUrl } = usePage().props;

	return (
		<FamedicLayout title="Bienvenido">
			<Hero invitationUrl={invitationUrl} auth={auth} />
			<CTA />
			<QuickLinks />
		</FamedicLayout>
	);
}
