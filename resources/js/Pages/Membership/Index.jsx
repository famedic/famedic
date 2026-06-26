import { useRef, useState } from "react";
import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Divider } from "@/Components/Catalyst/divider";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import Card from "@/Components/Card";
import { HeartIcon } from "@heroicons/react/24/solid";
import MembershipHero from "@/Components/Membership/MembershipHero";
import MembershipFamily from "@/Components/Membership/MembershipFamily";
import MembershipTabs from "@/Components/Membership/MembershipTabs";
import MembershipBenefits, {
	BenefitsModal,
} from "@/Components/Membership/MembershipBenefits";
import MembershipRenewBanner from "@/Components/Membership/MembershipRenewBanner";
import MembershipFAQ from "@/Components/Membership/MembershipFAQ";
import QuickActions from "@/Components/Membership/QuickActions";

export default function MembershipIndex({ membership }) {
	const hasMembership = membership?.status?.status !== "none";
	const [showBenefits, setShowBenefits] = useState(false);
	const [activeTab, setActiveTab] = useState("resumen");
	const tabsRef = useRef(null);

	const scrollToTabs = (tabKey) => {
		setActiveTab(tabKey);
		tabsRef.current?.scrollIntoView({
			behavior: "smooth",
			block: "start",
		});
	};

	return (
		<SettingsLayout title="Mi Membresía">
			<div className="space-y-6 lg:space-y-8">
				<header className="space-y-2">
					<div className="flex items-center gap-3">
						<div className="flex size-10 items-center justify-center rounded-2xl bg-rose-50 text-rose-500 dark:bg-rose-500/10">
							<HeartIcon className="size-5" />
						</div>
						<GradientHeading noDivider className="!mb-0">
							Mi Membresía
						</GradientHeading>
					</div>
					<Text className="max-w-2xl text-zinc-600 dark:text-slate-300">
						Estado, familia y beneficios de tu membresía médica en un
						solo lugar.
					</Text>
				</header>

				<Divider className="!my-0" />

				{!hasMembership ? (
					<MembershipEmptyState renewUrl={membership?.status?.renewUrl} />
				) : (
					<div className="space-y-6 lg:space-y-8">
						<MembershipRenewBanner renewal={membership.renewal} />

						<MembershipHero
							status={membership.status}
							progress={membership.progress}
							access={membership.access}
							plan={membership.plan}
							capabilities={membership.capabilities}
							onShowBenefits={() => setShowBenefits(true)}
						/>

						<MembershipFamily
							coverage={membership.coverage}
							capabilities={membership.capabilities}
							onViewAll={() => scrollToTabs("cobertura")}
						/>

						<MembershipBenefits
							benefits={membership.benefits}
							compact
						/>

						<QuickActions
							membership={membership}
							onAction={(action) => {
								if (action === "benefits") {
									setShowBenefits(true);
								}
							}}
						/>

						<div ref={tabsRef}>
							<MembershipTabs
								membership={membership}
								activeTab={activeTab}
								onTabChange={setActiveTab}
							/>
						</div>

						<MembershipFAQ faq={membership.faq} />
					</div>
				)}
			</div>

			<BenefitsModal
				open={showBenefits}
				onClose={setShowBenefits}
				benefits={membership?.benefits ?? []}
			/>
		</SettingsLayout>
	);
}

function MembershipEmptyState({ renewUrl }) {
	return (
		<Card className="overflow-hidden rounded-[20px] shadow-sm ring-1 ring-slate-100">
			<div className="px-6 py-12 text-center sm:px-10 sm:py-16">
				<div className="mx-auto flex size-16 items-center justify-center rounded-3xl bg-rose-50 text-rose-500 dark:bg-rose-500/10">
					<HeartIcon className="size-8" />
				</div>
				<h2 className="mt-6 font-poppins text-2xl font-semibold text-famedic-dark dark:text-white">
					Aún no tienes una membresía activa
				</h2>
				<Text className="mx-auto mt-3 max-w-lg text-zinc-600 dark:text-slate-300">
					Contrata la Membresía Médica Anual y obtén atención 24/7
					para ti y tu familia con telemedicina, psicología y
					nutrición.
				</Text>
				<div className="mt-8 flex justify-center">
					<Button href={renewUrl ?? route("medical-attention.checkout")}>
						Contratar membresía
					</Button>
				</div>
			</div>
		</Card>
	);
}
