import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";

export default function FeaturesGrid({ features }) {
	return (
		<section className="rounded-lg bg-white shadow-sm dark:bg-slate-900">
			<div className="px-4 py-24 sm:px-6 lg:px-8">
				<div className="grid grid-cols-1 gap-y-24 p-8 sm:grid-cols-2 sm:gap-x-8 lg:grid-cols-4 lg:gap-x-8 lg:gap-y-0">
					{features.map((feature) => (
						<div
							key={feature.name}
							className="flex flex-col gap-8 text-center md:flex-row md:text-left lg:text-center"
						>
							<div className="space-y-10 md:flex md:gap-8 md:space-y-0 lg:block lg:space-y-10">
								<div className="flex-shrink-0">
									<div className="flow-root">
										<div className="relative flex items-center justify-center">
											<div className="absolute size-16 rounded-full bg-sky-50 dark:bg-sky-950/50" />
											<feature.icon className="relative mx-auto size-8 fill-famedic-dark dark:fill-famedic-light" />
										</div>
									</div>
								</div>
								<div>
									<Subheading>{feature.name}</Subheading>
									<Text className="mt-3">
										{feature.description}
									</Text>
								</div>
							</div>
						</div>
					))}
				</div>
			</div>
		</section>
	);
}
