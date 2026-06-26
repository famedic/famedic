import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Divider } from "@/Components/Catalyst/divider";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import Card from "@/Components/Card";
import { HeartIcon } from "@heroicons/react/24/solid";
import MembershipStatusCard from "@/Components/Membership/MembershipStatusCard";
import MembershipAccessCard from "@/Components/Membership/MembershipAccessCard";
import MembershipProgress from "@/Components/Membership/MembershipProgress";
import MembershipBenefits from "@/Components/Membership/MembershipBenefits";
import MembershipPlanInfo from "@/Components/Membership/MembershipPlanInfo";
import MembershipPaymentCard from "@/Components/Membership/MembershipPaymentCard";
import MembershipHistory from "@/Components/Membership/MembershipHistory";
import MembershipCoverage from "@/Components/Membership/MembershipCoverage";
import MembershipRenewBanner from "@/Components/Membership/MembershipRenewBanner";
import MembershipUsage from "@/Components/Membership/MembershipUsage";
import MembershipFAQ from "@/Components/Membership/MembershipFAQ";

export default function MembershipIndex({ membership }) {
	const hasMembership = membership?.status?.status !== "none";

	return (
		<SettingsLayout title="Mi Membresía">
			<div className="space-y-8 lg:space-y-10">
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
						Consulta el estado, beneficios y detalles de tu
						membresía médica en un solo lugar.
					</Text>
				</header>

				<Divider className="!my-0" />

				{!hasMembership ? (
					<MembershipEmptyState renewUrl={membership?.status?.renewUrl} />
				) : (
					<div className="space-y-8 lg:space-y-10">
						<MembershipRenewBanner renewal={membership.renewal} />

						<MembershipStatusCard
							status={membership.status}
							capabilities={membership.capabilities}
						/>

						<MembershipAccessCard access={membership.access} />

						<MembershipProgress progress={membership.progress} />

						<MembershipBenefits benefits={membership.benefits} />

						<div className="grid gap-6 xl:grid-cols-2">
							<MembershipPlanInfo plan={membership.plan} />
							<MembershipPaymentCard
								payment={membership.payment}
								capabilities={membership.capabilities}
							/>
						</div>

						<MembershipCoverage
							coverage={membership.coverage}
							capabilities={membership.capabilities}
						/>

						<MembershipUsage usage={membership.usage} />

						<MembershipHistory history={membership.history} />

						<MembershipFAQ faq={membership.faq} />
					</div>
				)}
			</div>
		</SettingsLayout>
	);
}

function MembershipEmptyState({ renewUrl }) {
	return (
		<Card className="overflow-hidden shadow-sm ring-1 ring-slate-100">
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
