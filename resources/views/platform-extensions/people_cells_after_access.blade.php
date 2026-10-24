<td class="text-right font-bold text-[#5f6a71]">{{ $person->open_assignments_count }}</td>
                            <td class="text-right font-bold {{ $person->overdue_assignments_count > 0 ? 'text-[#b23a3a]' : 'text-[#8a9298]' }}">{{ $person->overdue_assignments_count }}</td>
