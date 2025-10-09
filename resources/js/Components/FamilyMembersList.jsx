import React from "react";
import { Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import MedicalAttentionBadge from "@/Components/MedicalAttentionBadge";
import {
	DescriptionList,
	DescriptionTerm,
	DescriptionDetails,
} from "@/Components/Catalyst/description-list";

export default function FamilyMembersList({
	familyMembers,
	showMedicalId = false,
}) {
	return (
		<DescriptionList>
			{familyMembers.map((member) => (
				<React.Fragment key={member.id}>
					<DescriptionTerm>
						{member.formatted_kinship}
					</DescriptionTerm>
					<DescriptionDetails>
						<div className="space-y-2">
							<div>
								<Strong>{member.full_name}</Strong>
							</div>
							<div className="flex flex-wrap gap-2">
								{member.formatted_gender && (
									<Badge color="slate">
										{member.formatted_gender}
									</Badge>
								)}
								{member.formatted_birth_date && (
									<Badge color="slate">
										{member.formatted_birth_date}
									</Badge>
								)}
								{showMedicalId &&
									member.customer
										?.medical_attention_identifier && (
										<MedicalAttentionBadge
											isActive={
												member.customer
													?.medical_attention_subscription_is_active
											}
										>
											{
												member.customer
													.medical_attention_identifier
											}
										</MedicalAttentionBadge>
									)}
							</div>
						</div>
					</DescriptionDetails>
				</React.Fragment>
			))}
		</DescriptionList>
	);
}
