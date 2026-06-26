import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Anchor, Text } from "@/Components/Catalyst/text";
import {
	PhoneIcon,
	QrCodeIcon,
	StarIcon,
} from "@heroicons/react/24/solid";
import { StarIcon as StarIconOutline } from "@heroicons/react/24/outline";

export default function MembershipAccessCard({ access }) {
	if (!access?.identifier) {
		return null;
	}

	return (
		<Card className="overflow-hidden shadow-sm ring-1 ring-slate-100">
			<div className="grid gap-6 p-6 sm:p-8 lg:grid-cols-2 lg:items-center lg:gap-10">
				<div className="space-y-6 text-center lg:text-left">
					<div className="space-y-2">
						<Badge color="slate" className="w-fit mx-auto lg:mx-0">
							<QrCodeIcon className="size-4" />
							Número de identificación
						</Badge>
						<p className="font-poppins text-4xl font-bold tracking-tight text-famedic-dark dark:text-white sm:text-5xl">
							{access.identifier}
						</p>
						<Text className="text-sm text-zinc-500">
							Usa este número al contactar la línea de atención
							médica.
						</Text>
					</div>

					{access.isPremium ? (
						<Badge color="amber" className="w-fit mx-auto lg:mx-0">
							<StarIcon className="size-4 text-amber-400" />
							Membresía Premium
						</Badge>
					) : (
						<Badge color="sky" className="w-fit mx-auto lg:mx-0">
							<StarIconOutline className="size-4" />
							Membresía Básica
						</Badge>
					)}
				</div>

				<div className="space-y-4 text-center lg:text-left">
					<Badge
						color={access.isPremium ? "amber" : "sky"}
						className="w-fit mx-auto lg:mx-0"
					>
						<PhoneIcon className="size-4" />
						{access.lineLabel}
					</Badge>

					<Anchor href={access.telHref} className="block">
						<Button
							color={access.isPremium ? "amber" : "blue"}
							className="w-full !py-4 text-xl sm:text-2xl"
						>
							<PhoneIcon className="size-6" />
							{access.formattedPhone}
						</Button>
					</Anchor>

					<Text className="text-sm text-zinc-500">
						{access.lineHint}
					</Text>

					<Text className="text-sm leading-relaxed text-zinc-600 dark:text-slate-300">
						{access.instruction}
					</Text>
				</div>
			</div>
		</Card>
	);
}
