import Card from "@/Components/Card";
import { Text } from "@/Components/Catalyst/text";
import {
	HeartIcon,
	ClockIcon,
	UsersIcon,
	VideoCameraIcon,
	CheckIcon,
} from "@heroicons/react/24/outline";
import {
	SparklesIcon,
} from "@heroicons/react/24/solid";

const ICON_MAP = {
	heart: HeartIcon,
	clock: ClockIcon,
	brain: SparklesIcon,
	nutrition: SparklesIcon,
	family: UsersIcon,
	video: VideoCameraIcon,
};

export default function MembershipBenefits({ benefits = [] }) {
	return (
		<section className="space-y-4">
			<div>
				<h3 className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
					Beneficios incluidos
				</h3>
				<Text className="text-sm text-zinc-500">
					Todo lo que tu membresía pone a tu disposición.
				</Text>
			</div>

			<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
				{benefits.map((benefit) => {
					const Icon = ICON_MAP[benefit.icon] ?? CheckIcon;

					return (
						<Card
							key={benefit.title}
							className="p-5 shadow-sm ring-1 ring-slate-100"
						>
							<div className="flex items-start gap-3">
								<div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300">
									<Icon className="size-5" />
								</div>
								<div className="min-w-0 space-y-1">
									<div className="flex items-center gap-2">
										<CheckIcon className="size-4 shrink-0 text-emerald-500" />
										<p className="font-medium text-zinc-800 dark:text-slate-100">
											{benefit.title}
										</p>
									</div>
									<Text className="text-sm leading-snug text-zinc-500">
										{benefit.description}
									</Text>
								</div>
							</div>
						</Card>
					);
				})}
			</div>
		</section>
	);
}
