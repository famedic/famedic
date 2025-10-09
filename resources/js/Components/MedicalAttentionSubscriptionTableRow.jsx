import { TableRow, TableCell } from "@/Components/Catalyst/table";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Avatar } from "@/Components/Catalyst/avatar";
import { UserGroupIcon } from "@heroicons/react/16/solid";
import CustomerInfo from "@/Components/CustomerInfo";
import PaymentMethodBadge from "@/Components/PaymentMethodBadge";
import MedicalAttentionBadge from "@/Components/MedicalAttentionBadge";

export default function MedicalAttentionSubscriptionTableRow({ subscription }) {
	return (
		<TableRow
			key={subscription.id}
			href={route(
				"admin.medical-attention-subscriptions.show",
				subscription.id,
			)}
			title={`Membresía médica #${subscription.customer.medical_attention_identifier}`}
			dusk={`showMedicalAttentionSubscription-${subscription.id}`}
		>
			<TableCell>
				<div className="space-y-1">
					<div className="flex items-center gap-2">
						<MedicalAttentionBadge
							isActive={
								subscription.is_active
							}
						>
							{
								subscription.customer
									.medical_attention_identifier
							}
						</MedicalAttentionBadge>
					</div>
					<span className="flex items-center gap-2">
						<Text>
							<Strong>
								{
									subscription.formatted_price
								}
							</Strong>
						</Text>
						{subscription.transactions?.length >
							0 && (
							<PaymentMethodBadge
								transaction={
									subscription
										.transactions[0]
								}
							/>
						)}
					</span>
				</div>
			</TableCell>

			<TableCell>
				<div className="flex items-center gap-2">
					<Avatar
						src={
							subscription.customer
								.customerable_type ===
							"App\\Models\\FamilyAccount"
								? subscription.customer
										.customerable
										?.profile_photo_url
								: subscription.customer.user
										?.profile_photo_url
						}
						className="size-12"
					/>
					<CustomerInfo
						customer={subscription.customer}
					/>
				</div>
			</TableCell>

			<TableCell>
				<div className="flex items-center gap-2">
					<UserGroupIcon className="size-4 fill-zinc-400 dark:fill-slate-600" />
					<Text>
						<Strong>
							{subscription.customer
								?.family_members?.length +
								1 || 1}
						</Strong>{" "}
						miembros
					</Text>
				</div>
			</TableCell>

			<TableCell className="text-right">
				<div className="space-y-1">
					<Text>
						{subscription.formatted_start_date}
					</Text>
					<Text>
						{subscription.formatted_end_date}
					</Text>
				</div>
			</TableCell>
		</TableRow>
	);
}